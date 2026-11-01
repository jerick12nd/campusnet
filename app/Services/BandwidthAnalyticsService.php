<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SampleStatus;
use App\Models\NetworkInterface;
use App\Monitoring\MonitoringManager;
use App\Support\SqlDialect;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Campus-wide analytics of the internet (WAN) link over a longer period, built from
 * stored samples only. Same honesty rules as the dashboard: only samples of the active
 * driver are used and demo devices are ignored unless the mock driver is active.
 *
 * How the numbers are made:
 *  - Utilization is the per-sample value stored at poll time (busiest direction as a
 *    share of the configured internet capacity). With several WAN links it is the
 *    average per link, not the sum.
 *  - "Time above a threshold" is weighted by the seconds each sample covers.
 *  - Data volume is rate x elapsed seconds, so periods without a valid measurement
 *    are not counted: it is a lower bound whenever there are gaps.
 */
final class BandwidthAnalyticsService
{
    public const DEFAULT_RANGE = '7d';

    /** Range key => number of days. */
    public const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90];

    private const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly MonitoringManager $monitoring,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $range = self::DEFAULT_RANGE, ?CarbonImmutable $now = null): array
    {
        $range = isset(self::RANGES[$range]) ? $range : self::DEFAULT_RANGE;
        $days = self::RANGES[$range];
        $to = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $from = $to->subDays($days);

        $settings = $this->settings->all();
        $source = $this->monitoring->driverName();
        $timezone = $this->timezone((string) $settings['timezone']);
        $wanIds = $this->wanInterfaceIds($source);

        $payload = [
            'range' => $range,
            'ranges' => array_keys(self::RANGES),
            'days' => $days,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'mode' => ['demo' => $this->monitoring->isDemo(), 'driver' => $source],
            'capacity_mbps' => (float) $settings['bandwidth_capacity_mbps'],
            'thresholds' => [
                'warning' => (float) $settings['warning_threshold_percent'],
                'critical' => (float) $settings['critical_threshold_percent'],
            ],
            'wan' => ['configured' => $wanIds !== [], 'interfaces' => count($wanIds)],
            'has_data' => false,
            'summary' => null,
            'hourly' => [],
            'weekday' => [],
            'daily' => [],
            'busiest' => ['hour' => null, 'hour_avg' => null, 'day' => null, 'day_avg' => null],
            'top_interfaces' => $this->topInterfaces($source, $from, $to),
        ];

        if ($wanIds === []) {
            return $payload;
        }

        $samples = fn (CarbonImmutable $start, CarbonImmutable $end): QueryBuilder => DB::table('traffic_samples')
            ->whereIn('network_interface_id', $wanIds)
            ->where('source', $source)
            ->where('status', SampleStatus::Ok->value)
            ->whereNotNull('utilization_percent')
            ->where('sampled_at', '>=', $this->stamp($start))
            ->where('sampled_at', '<=', $this->stamp($end));

        $stats = $samples($from, $to)
            ->selectRaw(
                'COUNT(*) as n, AVG(utilization_percent) as avg_util, SUM(elapsed_seconds) as total_seconds, '
                .'SUM(CASE WHEN utilization_percent >= ? THEN elapsed_seconds ELSE 0 END) as warn_seconds, '
                .'SUM(CASE WHEN utilization_percent >= ? THEN elapsed_seconds ELSE 0 END) as crit_seconds',
                [$payload['thresholds']['warning'], $payload['thresholds']['critical']],
            )
            ->first();

        $count = (int) ($stats->n ?? 0);

        if ($count === 0) {
            return $payload;
        }

        // Nearest-rank 95th percentile: the value that 95 % of the samples do not exceed.
        $p95 = $samples($from, $to)
            ->orderBy('utilization_percent')
            ->offset(max(0, (int) ceil(0.95 * $count) - 1))
            ->limit(1)
            ->value('utilization_percent');

        $peak = $samples($from, $to)->orderByDesc('utilization_percent')->first(['utilization_percent', 'sampled_at']);

        $previous = $samples($from->subDays($days), $from->subSecond())->avg('utilization_percent');

        $profile = $this->profile($samples($from, $to), $timezone);

        $totalSeconds = (float) ($stats->total_seconds ?? 0);

        $payload['has_data'] = true;
        $payload['summary'] = [
            'sample_count' => $count,
            'avg_utilization_percent' => round((float) $stats->avg_util, 2),
            'previous_avg_utilization_percent' => $previous === null ? null : round((float) $previous, 2),
            'p95_utilization_percent' => $p95 === null ? null : round((float) $p95, 2),
            'peak_utilization_percent' => $peak === null ? null : round((float) $peak->utilization_percent, 2),
            'peak_at' => $peak === null ? null : CarbonImmutable::parse($peak->sampled_at, 'UTC')->toIso8601String(),
            'above_warning_percent' => $totalSeconds > 0 ? round((float) $stats->warn_seconds / $totalSeconds * 100, 1) : null,
            'above_critical_percent' => $totalSeconds > 0 ? round((float) $stats->crit_seconds / $totalSeconds * 100, 1) : null,
            'transferred' => $profile['transferred'],
        ];
        $payload['hourly'] = $profile['hourly'];
        $payload['weekday'] = $profile['weekday'];
        $payload['daily'] = $profile['daily'];
        $payload['busiest'] = $profile['busiest'];

        return $payload;
    }

    /**
     * Hour-of-day, day-of-week and daily-volume figures. Samples are grouped into hourly
     * buckets in SQL and regrouped into the campus time zone here, so the SQL stays the
     * same on every database.
     *
     * @return array{
     *     hourly: list<array{hour: int, avg_utilization_percent: float|null}>,
     *     weekday: list<array{day: int, label: string, avg_utilization_percent: float|null}>,
     *     daily: list<array{date: string, in_mb: float, out_mb: float}>,
     *     busiest: array{hour: int|null, hour_avg: float|null, day: string|null, day_avg: float|null},
     *     transferred: array{in_mb: float, out_mb: float}
     * }
     */
    private function profile(QueryBuilder $samples, string $timezone): array
    {
        $bucketSql = SqlDialect::bucket('sampled_at', 3600);

        $rows = $samples
            ->selectRaw("{$bucketSql} as bucket, SUM(utilization_percent) as sum_util, COUNT(*) as n, SUM(in_mbps * elapsed_seconds) as in_mbit, SUM(out_mbps * elapsed_seconds) as out_mbit")
            ->groupByRaw('bucket')
            ->orderByRaw('bucket')
            ->get();

        $hours = array_fill(0, 24, ['sum' => 0.0, 'n' => 0]);
        $weekdays = array_fill(1, 7, ['sum' => 0.0, 'n' => 0]);
        $daily = [];
        $inMbit = 0.0;
        $outMbit = 0.0;

        foreach ($rows as $row) {
            $local = CarbonImmutable::createFromTimestampUTC((int) $row->bucket)->setTimezone($timezone);
            $sum = (float) $row->sum_util;
            $n = (int) $row->n;

            $hours[$local->hour]['sum'] += $sum;
            $hours[$local->hour]['n'] += $n;
            $weekdays[$local->dayOfWeekIso]['sum'] += $sum;
            $weekdays[$local->dayOfWeekIso]['n'] += $n;

            $date = $local->format('Y-m-d');
            $daily[$date] ??= ['in' => 0.0, 'out' => 0.0];
            $daily[$date]['in'] += (float) $row->in_mbit;
            $daily[$date]['out'] += (float) $row->out_mbit;

            $inMbit += (float) $row->in_mbit;
            $outMbit += (float) $row->out_mbit;
        }

        $hourly = [];
        $busiestHour = null;

        foreach ($hours as $hour => $acc) {
            $avg = $acc['n'] > 0 ? round($acc['sum'] / $acc['n'], 2) : null;
            $hourly[] = ['hour' => $hour, 'avg_utilization_percent' => $avg];

            if ($avg !== null && ($busiestHour === null || $avg > $busiestHour['avg'])) {
                $busiestHour = ['hour' => $hour, 'avg' => $avg];
            }
        }

        $weekday = [];
        $busiestDay = null;

        foreach ($weekdays as $day => $acc) {
            $avg = $acc['n'] > 0 ? round($acc['sum'] / $acc['n'], 2) : null;
            $weekday[] = ['day' => $day, 'label' => self::WEEKDAYS[$day], 'avg_utilization_percent' => $avg];

            if ($avg !== null && ($busiestDay === null || $avg > $busiestDay['avg'])) {
                $busiestDay = ['label' => self::WEEKDAYS[$day], 'avg' => $avg];
            }
        }

        ksort($daily);

        return [
            'hourly' => $hourly,
            'weekday' => $weekday,
            // megabits -> megabytes
            'daily' => array_map(
                fn (string $date, array $volume): array => ['date' => $date, 'in_mb' => round($volume['in'] / 8, 2), 'out_mb' => round($volume['out'] / 8, 2)],
                array_keys($daily),
                array_values($daily),
            ),
            'busiest' => [
                'hour' => $busiestHour['hour'] ?? null,
                'hour_avg' => $busiestHour['avg'] ?? null,
                'day' => $busiestDay['label'] ?? null,
                'day_avg' => $busiestDay['avg'] ?? null,
            ],
            'transferred' => ['in_mb' => round($inMbit / 8, 2), 'out_mb' => round($outMbit / 8, 2)],
        ];
    }

    /**
     * The interfaces that carry the most data in the period. Interfaces of every role
     * are listed, because a busy LAN uplink matters as much as the internet link.
     *
     * @return list<array<string, mixed>>
     */
    private function topInterfaces(string $source, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('traffic_samples')
            ->selectRaw(
                'network_interface_id, SUM(in_mbps * elapsed_seconds) as in_mbit, SUM(out_mbps * elapsed_seconds) as out_mbit, '
                .'AVG(in_mbps) as avg_in, AVG(out_mbps) as avg_out, MAX(in_mbps) as max_in, MAX(out_mbps) as max_out'
            )
            ->where('source', $source)
            ->where('status', SampleStatus::Ok->value)
            ->whereNotNull('elapsed_seconds')
            ->where('sampled_at', '>=', $this->stamp($from))
            ->where('sampled_at', '<=', $this->stamp($to))
            ->groupBy('network_interface_id')
            ->orderByRaw('(SUM(in_mbps * elapsed_seconds) + SUM(out_mbps * elapsed_seconds)) DESC')
            ->limit(10)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $interfaces = NetworkInterface::query()
            ->with(['device' => fn ($query) => $query->withTrashed()->select('id', 'name')])
            ->whereIn('id', $rows->pluck('network_interface_id')->all())
            ->get()
            ->keyBy('id');

        return $rows->map(function (object $row) use ($interfaces): array {
            $interface = $interfaces->get($row->network_interface_id);
            $in = (float) $row->in_mbit / 8;
            $out = (float) $row->out_mbit / 8;

            return [
                'id' => (int) $row->network_interface_id,
                'name' => $interface?->name ?? 'Unknown interface',
                'device' => $interface?->device?->name,
                'role' => $interface?->role->label(),
                'in_mb' => round($in, 2),
                'out_mb' => round($out, 2),
                'total_mb' => round($in + $out, 2),
                'avg_total_mbps' => round((float) $row->avg_in + (float) $row->avg_out, 2),
                'peak_mbps' => round(max((float) $row->max_in, (float) $row->max_out), 2),
            ];
        })->values()->all();
    }

    /**
     * @return list<int>
     */
    private function wanInterfaceIds(string $source): array
    {
        return NetworkInterface::query()
            ->wan()
            ->monitored()
            ->whereHas('device', fn (Builder $device) => $device
                ->where('is_enabled', true)
                ->when($source !== 'mock', fn (Builder $q) => $q->where('is_demo', false)))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function stamp(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:s');
    }

    private function timezone(string $name): string
    {
        return in_array($name, DateTimeZone::listIdentifiers(), true) ? $name : 'UTC';
    }
}