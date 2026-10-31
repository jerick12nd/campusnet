<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeviceStatus;
use App\Enums\SampleStatus;
use App\Models\MonitoredDevice;
use App\Models\NetworkAlert;
use App\Models\NetworkInterface;
use App\Models\TrafficSample;
use App\Monitoring\MonitoringManager;
use App\Support\Bandwidth;
use App\Support\SqlDialect;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the dashboard payload from stored data only. Nothing here is hardcoded.
 *
 * Honesty rules:
 *  - Only samples whose `source` equals the active driver are used, so simulated
 *    (mock) samples can never appear once a real driver is selected, and vice versa.
 *  - Demo devices (is_demo) are ignored unless the mock driver is active.
 *  - "Current" values come from the latest valid sample and are flagged stale when
 *    that sample is older than the configured stale-data window.
 */
final class DashboardService
{
    public const DEFAULT_RANGE = '24h';

    /** Range length and chart bucket size, in seconds. */
    public const RANGES = [
        '1h' => ['seconds' => 3600, 'bucket' => 60],
        '24h' => ['seconds' => 86400, 'bucket' => 900],
        '7d' => ['seconds' => 604800, 'bucket' => 3600],
        '30d' => ['seconds' => 2592000, 'bucket' => 21600],
    ];

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
        $config = self::RANGES[$range];
        $now ??= CarbonImmutable::now();
        $from = $now->subSeconds($config['seconds']);

        $settings = $this->settings->all();
        $source = $this->monitoring->driverName();
        $capacity = (float) $settings['bandwidth_capacity_mbps'];
        $staleAfter = $now->subMinutes((int) $settings['stale_data_minutes']);

        $wanInterfaces = $this->wanInterfaces($source);
        $devices = $this->deviceCounts($source);
        $latestAt = $this->latestMeasurementAt($source);
        $history = $this->history($wanInterfaces->pluck('id')->all(), $source, $from, $config['bucket'], $capacity);

        return [
            'range' => $range,
            'ranges' => array_keys(self::RANGES),
            'mode' => ['demo' => $this->monitoring->isDemo(), 'driver' => $source],
            'capacity_mbps' => $capacity,
            'thresholds' => [
                'warning' => (float) $settings['warning_threshold_percent'],
                'critical' => (float) $settings['critical_threshold_percent'],
            ],
            'refresh_seconds' => (int) $settings['dashboard_refresh_seconds'],
            'generated_at' => $now->toIso8601String(),
            'collector' => $this->collector($devices['total'], $latestAt, $staleAfter),
            'latest_measurement_at' => $latestAt?->toIso8601String(),
            'wan' => $this->wan($wanInterfaces, $source, $capacity, $staleAfter),
            'devices' => $devices,
            'alerts' => $this->alertCounts($source),
            'history' => $history['points'],
            'summary' => $history['summary'],
            'top_interfaces' => $this->topInterfaces($source, $from),
            'incidents' => $this->incidents($source),
        ];
    }

    /**
     * @return Builder<MonitoredDevice>
     */
    private function deviceQuery(string $source): Builder
    {
        return MonitoredDevice::query()
            ->when($source !== 'mock', fn (Builder $query) => $query->where('is_demo', false));
    }

    /**
     * @return Builder<NetworkAlert>
     */
    private function alertQuery(string $source): Builder
    {
        return NetworkAlert::query()->where(function (Builder $query) use ($source): void {
            $query->whereNull('monitored_device_id')
                ->orWhereHas('device', fn (Builder $device) => $device
                    ->when($source !== 'mock', fn (Builder $q) => $q->where('is_demo', false)));
        });
    }

    /**
     * @return Collection<int, NetworkInterface>
     */
    private function wanInterfaces(string $source): Collection
    {
        return NetworkInterface::query()
            ->with('device:id,name')
            ->wan()
            ->monitored()
            ->whereHas('device', fn (Builder $device) => $device
                ->where('is_enabled', true)
                ->when($source !== 'mock', fn (Builder $q) => $q->where('is_demo', false)))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{total: int, online: int, unreachable: int, unknown: int, disabled: int}
     */
    private function deviceCounts(string $source): array
    {
        /** @var Collection<string, int|string> $byStatus */
        $byStatus = $this->deviceQuery($source)
            ->where('is_enabled', true)
            ->selectRaw('last_status, COUNT(*) as aggregate')
            ->groupBy('last_status')
            ->toBase()
            ->pluck('aggregate', 'last_status');

        $online = (int) ($byStatus[DeviceStatus::Online->value] ?? 0);
        $unreachable = (int) ($byStatus[DeviceStatus::Unreachable->value] ?? 0);
        $unknown = (int) ($byStatus[DeviceStatus::Unknown->value] ?? 0);

        return [
            'total' => $online + $unreachable + $unknown,
            'online' => $online,
            'unreachable' => $unreachable,
            'unknown' => $unknown,
            'disabled' => $this->deviceQuery($source)->where('is_enabled', false)->count(),
        ];
    }

    private function latestMeasurementAt(string $source): ?CarbonImmutable
    {
        $sample = TrafficSample::query()
            ->where('source', $source)
            ->where('is_successful', true)
            ->latest('sampled_at')
            ->first(['sampled_at']);

        return $sample ? CarbonImmutable::instance($sample->sampled_at) : null;
    }

    /**
     * @return array{status: string, message: string|null}
     */
    private function collector(int $enabledDevices, ?CarbonImmutable $latestAt, CarbonImmutable $staleAfter): array
    {
        if (! $this->monitoring->isKnownDriver()) {
            return ['status' => 'misconfigured', 'message' => 'BANDWIDTH_MONITORING_DRIVER has an unknown value. Use mock or snmp.'];
        }

        if ($enabledDevices === 0) {
            return ['status' => 'no_devices', 'message' => 'No devices are being monitored yet. Add a device on the Network Devices page.'];
        }

        if ($latestAt === null) {
            return ['status' => 'no_data', 'message' => 'No measurements have been collected yet.'];
        }

        if ($latestAt < $staleAfter) {
            return ['status' => 'stale', 'message' => 'The last measurement is '.$latestAt->diffForHumans().'. The collector may be offline, so the values below are not current.'];
        }

        return ['status' => 'ok', 'message' => null];
    }

    /**
     * @param  Collection<int, NetworkInterface>  $wanInterfaces
     * @return array<string, mixed>
     */
    private function wan(Collection $wanInterfaces, string $source, float $capacity, CarbonImmutable $staleAfter): array
    {
        $interfaces = $wanInterfaces->map(fn (NetworkInterface $interface): array => [
            'id' => $interface->id,
            'name' => $interface->name,
            'device' => $interface->device?->name,
        ])->values()->all();

        $latest = $wanInterfaces
            ->map(fn (NetworkInterface $interface) => $interface->trafficSamples()
                ->where('source', $source)
                ->withValidRate()
                ->latest('sampled_at')
                ->first())
            ->filter()
            ->values();

        if ($latest->isEmpty()) {
            return ['configured' => $wanInterfaces->isNotEmpty(), 'interfaces' => $interfaces, 'current' => null];
        }

        $in = (float) $latest->sum(fn (TrafficSample $sample): float => (float) $sample->in_mbps);
        $out = (float) $latest->sum(fn (TrafficSample $sample): float => (float) $sample->out_mbps);
        $sampledAt = CarbonImmutable::instance($latest->sortByDesc(fn (TrafficSample $s): int => $s->sampled_at->getTimestamp())->first()->sampled_at);

        return [
            'configured' => true,
            'interfaces' => $interfaces,
            'current' => [
                'in_mbps' => round($in, 2),
                'out_mbps' => round($out, 2),
                'utilization_percent' => Bandwidth::utilizationPercent($in, $out, $capacity),
                'sampled_at' => $sampledAt->toIso8601String(),
                'stale' => $sampledAt < $staleAfter,
                'interfaces_reporting' => $latest->count(),
                'interfaces_total' => $wanInterfaces->count(),
            ],
        ];
    }

    /**
     * Bucketed history of the WAN interfaces. Values of several WAN interfaces are summed
     * per bucket. The per-bucket peak is the sum of each interface's bucket maximum, which
     * is exact for a single WAN link and an upper bound when several links peak at
     * different moments.
     *
     * @param  list<int>  $interfaceIds
     * @return array{points: list<array<string, mixed>>, summary: array<string, float|null>|null}
     */
    private function history(array $interfaceIds, string $source, CarbonImmutable $from, int $bucket, float $capacity): array
    {
        if ($interfaceIds === []) {
            return ['points' => [], 'summary' => null];
        }

        $bucketSql = SqlDialect::bucket('sampled_at', $bucket);

        $rows = DB::table('traffic_samples')
            ->selectRaw("{$bucketSql} as bucket, network_interface_id, AVG(in_mbps) as avg_in, AVG(out_mbps) as avg_out, MAX(in_mbps) as max_in, MAX(out_mbps) as max_out")
            ->whereIn('network_interface_id', $interfaceIds)
            ->where('source', $source)
            ->where('status', SampleStatus::Ok->value)
            ->where('sampled_at', '>=', $from)
            ->groupByRaw('bucket, network_interface_id')
            ->orderByRaw('bucket')
            ->get();

        $points = $rows->groupBy('bucket')->map(function (Collection $group, $bucketStart) use ($capacity): array {
            $in = (float) $group->sum('avg_in');
            $out = (float) $group->sum('avg_out');
            $peakIn = (float) $group->sum('max_in');
            $peakOut = (float) $group->sum('max_out');

            return [
                't' => CarbonImmutable::createFromTimestampUTC((int) $bucketStart)->toIso8601String(),
                'in_mbps' => round($in, 2),
                'out_mbps' => round($out, 2),
                'peak_in_mbps' => round($peakIn, 2),
                'peak_out_mbps' => round($peakOut, 2),
                'utilization_percent' => Bandwidth::utilizationPercent($in, $out, $capacity),
                'peak_utilization_percent' => Bandwidth::utilizationPercent($peakIn, $peakOut, $capacity),
            ];
        })->values();

        if ($points->isEmpty()) {
            return ['points' => [], 'summary' => null];
        }

        $averageUtilization = $points->pluck('utilization_percent')->filter(fn ($value) => $value !== null);
        $peakUtilization = $points->pluck('peak_utilization_percent')->filter(fn ($value) => $value !== null);

        return [
            'points' => $points->all(),
            'summary' => [
                'avg_utilization_percent' => $averageUtilization->isEmpty() ? null : round((float) $averageUtilization->avg(), 2),
                'peak_utilization_percent' => $peakUtilization->isEmpty() ? null : (float) $peakUtilization->max(),
                'avg_in_mbps' => round((float) $points->avg('in_mbps'), 2),
                'avg_out_mbps' => round((float) $points->avg('out_mbps'), 2),
                'peak_in_mbps' => (float) $points->max('peak_in_mbps'),
                'peak_out_mbps' => (float) $points->max('peak_out_mbps'),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topInterfaces(string $source, CarbonImmutable $from): array
    {
        /** @var Collection<int, object{network_interface_id: int, avg_in: string, avg_out: string}> $rows */
        $rows = DB::table('traffic_samples')
            ->selectRaw('network_interface_id, AVG(in_mbps) as avg_in, AVG(out_mbps) as avg_out')
            ->where('source', $source)
            ->where('status', SampleStatus::Ok->value)
            ->where('sampled_at', '>=', $from)
            ->groupBy('network_interface_id')
            ->orderByRaw('(AVG(in_mbps) + AVG(out_mbps)) DESC')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $interfaces = NetworkInterface::query()
            ->with(['device' => fn ($query) => $query->withTrashed()->select('id', 'name')])
            ->whereIn('id', $rows->pluck('network_interface_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function (object $row) use ($interfaces): array {
            $interface = $interfaces->get($row->network_interface_id);
            $in = (float) $row->avg_in;
            $out = (float) $row->avg_out;

            return [
                'id' => (int) $row->network_interface_id,
                'name' => $interface?->name ?? 'Unknown interface',
                'device' => $interface?->device?->name,
                'role' => $interface?->role->label(),
                'avg_in_mbps' => round($in, 2),
                'avg_out_mbps' => round($out, 2),
                'avg_total_mbps' => round($in + $out, 2),
            ];
        })->values()->all();
    }

    /**
     * @return array{active: int, critical: int}
     */
    private function alertCounts(string $source): array
    {
        return [
            'active' => $this->alertQuery($source)->open()->count(),
            'critical' => $this->alertQuery($source)->open()->critical()->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function incidents(string $source): array
    {
        return $this->alertQuery($source)
            ->with(['device' => fn ($query) => $query->select('id', 'name')])
            ->latest('triggered_at')
            ->limit(8)
            ->get()
            ->map(fn (NetworkAlert $alert): array => [
                'id' => $alert->id,
                'title' => $alert->title,
                'severity' => $alert->severity->value,
                'status' => $alert->status->value,
                'device' => $alert->device?->name,
                'triggered_at' => $alert->triggered_at->toIso8601String(),
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
            ])->all();
    }
}