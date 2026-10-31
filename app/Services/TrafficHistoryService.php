<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InterfaceRole;
use App\Enums\SampleStatus;
use App\Models\NetworkInterface;
use App\Monitoring\MonitoringManager;
use App\Support\Bandwidth;
use App\Support\SqlDialect;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * History of ONE interface over a chosen period, built from stored samples only.
 *
 * Same honesty rules as the dashboard: only samples whose `source` equals the active
 * driver are used, and demo devices are hidden unless the mock driver is active.
 * Only samples with a valid rate (status "ok") are drawn; everything else is counted
 * and reported as data quality, never silently dropped.
 */
final class TrafficHistoryService
{
    public const MAX_DAYS = 92;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly MonitoringManager $monitoring,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(
        string $range = DashboardService::DEFAULT_RANGE,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?int $interfaceId = null,
        ?CarbonImmutable $now = null,
    ): array {
        $now ??= CarbonImmutable::now('UTC');
        $settings = $this->settings->all();
        $timezone = $this->timezone((string) $settings['timezone']);
        $source = $this->monitoring->driverName();

        [$from, $to, $range] = $this->period($range, $fromDate, $toDate, $now, $timezone);
        $bucket = $this->bucketSeconds($to->getTimestamp() - $from->getTimestamp());

        $options = $this->interfaceOptions($source);
        $selected = $options->firstWhere('id', $interfaceId)
            ?? $options->first(fn (NetworkInterface $interface): bool => $interface->role === InterfaceRole::Wan)
            ?? $options->first();

        $payload = [
            'range' => $range,
            'ranges' => array_keys(DashboardService::RANGES),
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'from_date' => $from->setTimezone($timezone)->format('Y-m-d'),
            'to_date' => $to->setTimezone($timezone)->format('Y-m-d'),
            'bucket_seconds' => $bucket,
            'mode' => ['demo' => $this->monitoring->isDemo(), 'driver' => $source],
            'thresholds' => [
                'warning' => (float) $settings['warning_threshold_percent'],
                'critical' => (float) $settings['critical_threshold_percent'],
            ],
            'interfaces' => $options->map(fn (NetworkInterface $interface): array => [
                'id' => $interface->id,
                'name' => $interface->name,
                'device' => $interface->device?->name,
                'role' => $interface->role->label(),
                'is_wan' => $interface->role === InterfaceRole::Wan,
            ])->values()->all(),
            'selected' => null,
            'points' => [],
            'summary' => null,
            'transferred' => null,
            'quality' => $this->quality([]),
        ];

        if ($selected === null) {
            return $payload;
        }

        [$capacity, $basis] = $this->capacity($selected, (float) $settings['bandwidth_capacity_mbps']);

        $samples = fn (): QueryBuilder => DB::table('traffic_samples')
            ->where('network_interface_id', $selected->id)
            ->where('source', $source)
            ->whereBetween('sampled_at', [$from, $to]);

        $series = $this->series($samples, $bucket, $capacity);

        return [
            ...$payload,
            'selected' => [
                'id' => $selected->id,
                'name' => $selected->name,
                'device' => $selected->device?->name,
                'role' => $selected->role->label(),
                'is_wan' => $selected->role === InterfaceRole::Wan,
                'capacity_mbps' => $capacity,
                'capacity_basis' => $basis,
            ],
            'points' => $series['points'],
            'summary' => $series['summary'],
            'transferred' => $this->transferred($samples),
            'quality' => $this->quality(
                $samples()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->all(),
            ),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function period(string $range, ?string $fromDate, ?string $toDate, CarbonImmutable $now, string $timezone): array
    {
        if ($range === 'custom' && $fromDate !== null && $toDate !== null) {
            return [
                CarbonImmutable::parse($fromDate, $timezone)->startOfDay()->utc(),
                CarbonImmutable::parse($toDate, $timezone)->endOfDay()->utc(),
                'custom',
            ];
        }

        $range = isset(DashboardService::RANGES[$range]) ? $range : DashboardService::DEFAULT_RANGE;

        return [$now->subSeconds(DashboardService::RANGES[$range]['seconds']), $now, $range];
    }

    /** Longer periods use bigger buckets so a chart never has more than a few hundred points. */
    private function bucketSeconds(int $spanSeconds): int
    {
        return match (true) {
            $spanSeconds <= 7_200 => 60,
            $spanSeconds <= 172_800 => 900,
            $spanSeconds <= 1_209_600 => 3_600,
            $spanSeconds <= 3_888_000 => 21_600,
            default => 86_400,
        };
    }

    /**
     * @return Collection<int, NetworkInterface>
     */
    private function interfaceOptions(string $source): Collection
    {
        return NetworkInterface::query()
            ->with('device:id,name')
            ->monitored()
            ->whereHas('device', fn (Builder $device) => $device
                ->when($source !== 'mock', fn (Builder $q) => $q->where('is_demo', false)))
            ->get()
            ->sort(fn (NetworkInterface $a, NetworkInterface $b): int => [$a->device?->name, $a->name] <=> [$b->device?->name, $b->name])
            ->values();
    }

    /**
     * Utilization is measured against the internet capacity for a WAN interface and
     * against the link speed for any other interface. Without either, none is shown.
     *
     * @return array{0: float|null, 1: string|null}
     */
    private function capacity(NetworkInterface $interface, float $internetCapacity): array
    {
        if ($interface->role === InterfaceRole::Wan) {
            return $internetCapacity > 0 ? [$internetCapacity, 'internet_capacity'] : [null, null];
        }

        if ($interface->speed_mbps !== null && $interface->speed_mbps > 0) {
            return [(float) $interface->speed_mbps, 'link_speed'];
        }

        return [null, null];
    }

    /**
     * @param  callable(): QueryBuilder  $samples
     * @return array{points: list<array<string, mixed>>, summary: array<string, float|null>|null}
     */
    private function series(callable $samples, int $bucket, ?float $capacity): array
    {
        $bucketSql = SqlDialect::bucket('sampled_at', $bucket);

        $rows = $samples()
            ->where('status', SampleStatus::Ok->value)
            ->selectRaw("{$bucketSql} as bucket, AVG(in_mbps) as avg_in, AVG(out_mbps) as avg_out, MAX(in_mbps) as max_in, MAX(out_mbps) as max_out")
            ->groupByRaw('bucket')
            ->orderByRaw('bucket')
            ->get();

        $points = $rows->map(function (object $row) use ($capacity): array {
            $in = (float) $row->avg_in;
            $out = (float) $row->avg_out;
            $peakIn = (float) $row->max_in;
            $peakOut = (float) $row->max_out;

            return [
                't' => CarbonImmutable::createFromTimestampUTC((int) $row->bucket)->toIso8601String(),
                'in_mbps' => round($in, 2),
                'out_mbps' => round($out, 2),
                'peak_in_mbps' => round($peakIn, 2),
                'peak_out_mbps' => round($peakOut, 2),
                'utilization_percent' => Bandwidth::utilizationPercent($in, $out, $capacity ?? 0.0),
                'peak_utilization_percent' => Bandwidth::utilizationPercent($peakIn, $peakOut, $capacity ?? 0.0),
            ];
        })->values();

        if ($points->isEmpty()) {
            return ['points' => [], 'summary' => null];
        }

        $average = $points->pluck('utilization_percent')->filter(fn ($value) => $value !== null);
        $peak = $points->pluck('peak_utilization_percent')->filter(fn ($value) => $value !== null);

        return [
            'points' => $points->all(),
            'summary' => [
                'avg_utilization_percent' => $average->isEmpty() ? null : round((float) $average->avg(), 2),
                'peak_utilization_percent' => $peak->isEmpty() ? null : (float) $peak->max(),
                'avg_in_mbps' => round((float) $points->avg('in_mbps'), 2),
                'avg_out_mbps' => round((float) $points->avg('out_mbps'), 2),
                'peak_in_mbps' => (float) $points->max('peak_in_mbps'),
                'peak_out_mbps' => (float) $points->max('peak_out_mbps'),
            ],
        ];
    }

    /**
     * Each valid rate applies for the seconds since the previous poll, so rate x seconds
     * is the data that moved. Periods without a valid sample are not counted, which makes
     * the result a lower bound whenever there are gaps.
     *
     * @param  callable(): QueryBuilder  $samples
     * @return array{in_mb: float, out_mb: float}|null
     */
    private function transferred(callable $samples): ?array
    {
        $row = $samples()
            ->where('status', SampleStatus::Ok->value)
            ->whereNotNull('elapsed_seconds')
            ->selectRaw('SUM(in_mbps * elapsed_seconds) as in_mbit, SUM(out_mbps * elapsed_seconds) as out_mbit')
            ->first();

        if ($row === null || $row->in_mbit === null) {
            return null;
        }

        // megabits -> megabytes
        return [
            'in_mb' => round((float) $row->in_mbit / 8, 2),
            'out_mb' => round((float) ($row->out_mbit ?? 0) / 8, 2),
        ];
    }

    /**
     * @param  array<string, int|string>  $counts
     * @return array<string, int>
     */
    private function quality(array $counts): array
    {
        $quality = [];

        foreach (SampleStatus::cases() as $status) {
            $quality[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $quality;
    }

    private function timezone(string $name): string
    {
        return in_array($name, DateTimeZone::listIdentifiers(), true) ? $name : 'UTC';
    }
}