<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SampleStatus;
use App\Models\MonitoredDevice;
use App\Models\NetworkAlert;
use App\Models\NetworkInterface;
use App\Monitoring\MonitoringManager;
use App\Support\SqlDialect;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the CSV reports from stored data only. Same honesty rules as the dashboard:
 * only samples of the active driver are used, demo devices are hidden unless the mock
 * driver is active, and every traffic row says which source it came from.
 */
final class ReportService
{
    public const MAX_DAYS = 92;

    /** @var array<string, array{label: string, description: string}> */
    public const TYPES = [
        'traffic_daily' => [
            'label' => 'Daily traffic by interface',
            'description' => 'One row per interface per day: average and peak rates, data transferred and, for WAN interfaces, utilization. Only valid measurements are counted.',
        ],
        'alerts' => [
            'label' => 'Alerts log',
            'description' => 'Every alert raised in the period with its severity, status, who acknowledged or resolved it, and how long it lasted.',
        ],
        'device_availability' => [
            'label' => 'Device availability',
            'description' => 'One row per device: current status, last successful poll and the share of samples that were collected successfully in the period.',
        ],
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly MonitoringManager $monitoring,
        private readonly AlertListingService $alertListing,
    ) {}

    /**
     * The period as campus-local calendar days. Without dates it is the last seven days.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, from_date: string, to_date: string, timezone: string}
     */
    public function period(?string $from, ?string $to): array
    {
        $timezone = $this->timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $first = $from !== null ? CarbonImmutable::parse($from, $timezone)->startOfDay() : $today->subDays(6);
        $last = $to !== null ? CarbonImmutable::parse($to, $timezone)->startOfDay() : $today;

        return [
            'from' => $first->utc(),
            'to' => $last->endOfDay()->utc(),
            'from_date' => $first->format('Y-m-d'),
            'to_date' => $last->format('Y-m-d'),
            'timezone' => $timezone,
        ];
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, from_date: string, to_date: string, timezone: string}  $period
     * @return array{columns: list<string>, rows: list<list<scalar|null>>}
     */
    public function build(string $type, array $period): array
    {
        return match ($type) {
            'alerts' => $this->alerts($period),
            'device_availability' => $this->deviceAvailability($period),
            default => $this->trafficDaily($period),
        };
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, from_date: string, to_date: string, timezone: string}  $period
     * @return array{columns: list<string>, rows: list<list<scalar|null>>, total_rows: int}
     */
    public function preview(string $type, array $period, int $limit = 10): array
    {
        $report = $this->build($type, $period);

        return [
            'columns' => $report['columns'],
            'rows' => array_slice($report['rows'], 0, $limit),
            'total_rows' => count($report['rows']),
        ];
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, from_date: string, to_date: string, timezone: string}  $period
     */
    public function filename(string $type, array $period): string
    {
        return sprintf(
            'campusnet-%s%s-%s_%s.csv',
            str_replace('_', '-', $type),
            $this->monitoring->isDemo() ? '-demo' : '',
            $period['from_date'],
            $period['to_date'],
        );
    }

    /**
     * A cell that starts with = + - @ is read as a formula by Excel and Google Sheets, so a
     * device named "=HYPERLINK(...)" could run code on whoever opens the file. A leading
     * apostrophe turns it into plain text. Numbers are not touched.
     */
    public static function csvCell(mixed $value): string|int|float
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $text = (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }

    public function timezone(): string
    {
        $name = (string) $this->settings->get('timezone');

        return in_array($name, DateTimeZone::listIdentifiers(), true) ? $name : 'UTC';
    }

    /**
     * Hourly buckets are summed in SQL and regrouped into campus-local days here, so the
     * SQL is the same on every database and a day always means a day in campus time.
     *
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, from_date: string, to_date: string, timezone: string}  $period
     * @return array{columns: list<string>, rows: list<list<scalar|null>>}
     */
    private function trafficDaily(array $period): array
    {
        $source = $this->monitoring->driverName();
        $bucketSql = SqlDialect::bucket('sampled_at', 3600);

        $buckets = DB::table('traffic_samples')
            ->selectRaw(
                "{$bucketSql} as bucket, network_interface_id, COUNT(*) as n, "
                .'SUM(in_mbps) as sum_in, SUM(out_mbps) as sum_out, MAX(in_mbps) as max_in, MAX(out_mbps) as max_out, '
                .'SUM(in_mbps * elapsed_seconds) as in_mbit, SUM(out_mbps * elapsed_seconds) as out_mbit, '
                .'SUM(utilization_percent) as sum_util, COUNT(utilization_percent) as n_util, MAX(utilization_percent) as max_util'
            )
            ->where('source', $source)
            ->where('status', SampleStatus::Ok->value)
            ->where('sampled_at', '>=', $this->stamp($period['from']))
            ->where('sampled_at', '<=', $this->stamp($period['to']))
            ->groupByRaw('bucket, network_interface_id')
            ->get();

        $days = [];

        foreach ($buckets as $row) {
            $date = CarbonImmutable::createFromTimestampUTC((int) $row->bucket)->setTimezone($period['timezone'])->format('Y-m-d');
            $key = $date.'|'.$row->network_interface_id;

            $days[$key] ??= [
                'date' => $date,
                'interface' => (int) $row->network_interface_id,
                'n' => 0,
                'sum_in' => 0.0,
                'sum_out' => 0.0,
                'max_in' => 0.0,
                'max_out' => 0.0,
                'in_mbit' => 0.0,
                'out_mbit' => 0.0,
                'sum_util' => 0.0,
                'n_util' => 0,
                'max_util' => null,
            ];

            $days[$key]['n'] += (int) $row->n;
            $days[$key]['sum_in'] += (float) $row->sum_in;
            $days[$key]['sum_out'] += (float) $row->sum_out;
            $days[$key]['max_in'] = max($days[$key]['max_in'], (float) $row->max_in);
            $days[$key]['max_out'] = max($days[$key]['max_out'], (float) $row->max_out);
            $days[$key]['in_mbit'] += (float) $row->in_mbit;
            $days[$key]['out_mbit'] += (float) $row->out_mbit;
            $days[$key]['sum_util'] += (float) $row->sum_util;
            $days[$key]['n_util'] += (int) $row->n_util;

            if ($row->max_util !== null) {
                $days[$key]['max_util'] = max($days[$key]['max_util'] ?? 0.0, (float) $row->max_util);
            }
        }

        $interfaces = NetworkInterface::query()
            ->with(['device' => fn ($query) => $query->withTrashed()->select('id', 'name')])
            ->whereIn('id', array_values(array_unique(array_column($days, 'interface'))))
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($days as $day) {
            $interface = $interfaces->get($day['interface']);

            $rows[] = [
                'date' => $day['date'],
                'device' => $interface?->device?->name ?? 'Unknown device',
                'interface' => $interface?->name ?? 'Unknown interface',
                'role' => $interface?->role->label() ?? '',
                'values' => [
                    round($day['sum_in'] / $day['n'], 2),
                    round($day['sum_out'] / $day['n'], 2),
                    round($day['max_in'], 2),
                    round($day['max_out'], 2),
                    round($day['in_mbit'] / 8, 2),
                    round($day['out_mbit'] / 8, 2),
                    $day['n_util'] > 0 ? round($day['sum_util'] / $day['n_util'], 2) : '',
                    $day['max_util'] !== null ? round($day['max_util'], 2) : '',
                    $day['n'],
                ],
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['date'], $a['device'], $a['interface']] <=> [$b['date'], $b['device'], $b['interface']]);

        return [
            'columns' => [
                "Date ({$period['timezone']})", 'Device', 'Interface', 'Role',
                'Avg inbound (Mbps)', 'Avg outbound (Mbps)', 'Peak inbound (Mbps)', 'Peak outbound (Mbps)',
                'Data in (MB)', 'Data out (MB)', 'Avg utilization (%)', 'Peak utilization (%)', 'Valid samples', 'Source',
            ],
            'rows' => array_map(
                fn (array $row): array => [$row['date'], $row['device'], $row['interface'], $row['role'], ...$row['values'], $source],
                $rows,
            ),
        ];
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, from_date: string, to_date: string, timezone: string}  $period
     * @return array{columns: list<string>, rows: list<list<scalar|null>>}
     */
    private function alerts(array $period): array
    {
        $timezone = $period['timezone'];

        $alerts = $this->alertListing->visible()
            ->with([
                'device' => fn ($query) => $query->select('id', 'name'),
                'networkInterface:id,name',
                'acknowledgedBy:id,name',
                'resolvedBy:id,name',
            ])
            ->where('triggered_at', '>=', $this->stamp($period['from']))
            ->where('triggered_at', '<=', $this->stamp($period['to']))
            ->orderBy('triggered_at')
            ->orderBy('id')
            ->get();

        $rows = $alerts->map(function (NetworkAlert $alert) use ($timezone): array {
            $resolvedBy = $alert->resolved_at === null ? '' : ($alert->resolvedBy?->name ?? 'Automatic');

            return [
                $this->local($alert->triggered_at, $timezone),
                $alert->type->label(),
                $alert->severity->label(),
                $alert->status->label(),
                $alert->device?->name ?? 'System',
                $alert->networkInterface?->name ?? '',
                $alert->title,
                $alert->description ?? '',
                $alert->acknowledgedBy?->name ?? '',
                $this->local($alert->acknowledged_at, $timezone),
                $resolvedBy,
                $this->local($alert->resolved_at, $timezone),
                $alert->resolved_at === null ? '' : round(max(0, $alert->resolved_at->getTimestamp() - $alert->triggered_at->getTimestamp()) / 60, 1),
                $alert->resolution_note ?? '',
            ];
        })->all();

        return [
            'columns' => [
                "Triggered ({$timezone})", 'Type', 'Severity', 'Status', 'Device', 'Interface', 'Title', 'Description',
                'Acknowledged by', "Acknowledged at ({$timezone})", 'Resolved by', "Resolved at ({$timezone})", 'Duration (minutes)', 'Resolution note',
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Each poll stores one sample per monitored interface, so the success rate is the share
     * of samples that were collected successfully, not a count of whole polls.
     *
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, from_date: string, to_date: string, timezone: string}  $period
     * @return array{columns: list<string>, rows: list<list<scalar|null>>}
     */
    private function deviceAvailability(array $period): array
    {
        $source = $this->monitoring->driverName();
        $timezone = $period['timezone'];

        $devices = MonitoredDevice::query()
            ->when($source !== 'mock', fn (Builder $query) => $query->where('is_demo', false))
            ->orderBy('name')
            ->get();

        $samples = DB::table('traffic_samples')
            ->selectRaw('monitored_device_id, COUNT(*) as total, SUM(CASE WHEN is_successful = 1 THEN 0 ELSE 1 END) as failed')
            ->where('source', $source)
            ->where('sampled_at', '>=', $this->stamp($period['from']))
            ->where('sampled_at', '<=', $this->stamp($period['to']))
            ->groupBy('monitored_device_id')
            ->get()
            ->keyBy('monitored_device_id');

        $openAlerts = NetworkAlert::query()
            ->open()
            ->whereNotNull('monitored_device_id')
            ->selectRaw('monitored_device_id, COUNT(*) as aggregate')
            ->groupBy('monitored_device_id')
            ->pluck('aggregate', 'monitored_device_id');

        $rows = $devices->map(function (MonitoredDevice $device) use ($samples, $openAlerts, $timezone, $source): array {
            $row = $samples->get($device->id);
            $total = (int) ($row->total ?? 0);
            $failed = (int) ($row->failed ?? 0);

            return [
                $device->name,
                $device->management_ip,
                $this->words($device->device_type),
                $device->location ?? '',
                $device->is_enabled ? 'Yes' : 'No',
                $this->words($device->last_status),
                $this->local($device->last_successful_poll_at, $timezone),
                $total,
                $failed,
                $total > 0 ? round(($total - $failed) / $total * 100, 2) : '',
                (int) ($openAlerts[$device->id] ?? 0),
                $source,
            ];
        })->all();

        return [
            'columns' => [
                'Device', 'IP address', 'Type', 'Location', 'Monitoring enabled', 'Current status',
                "Last successful poll ({$timezone})", 'Samples in period', 'Failed samples', 'Success rate (%)', 'Open alerts now', 'Source',
            ],
            'rows' => $rows,
        ];
    }

    /** Readable text for an enum or plain string column: "access_point" becomes "Access point". */
    private function words(mixed $value): string
    {
        $text = $value instanceof \BackedEnum ? (string) $value->value : (string) $value;

        return ucfirst(str_replace('_', ' ', $text));
    }

    private function local(?CarbonInterface $at, string $timezone): string
    {
        return $at === null ? '' : CarbonImmutable::instance($at)->setTimezone($timezone)->format('Y-m-d H:i:s');
    }

    private function stamp(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:s');
    }
}