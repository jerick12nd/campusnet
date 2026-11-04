<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertType;
use App\Models\NetworkAlert;
use App\Monitoring\MonitoringManager;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Payload for the TV wallboard. It reuses DashboardService and BandwidthAnalyticsService
 * (same data, same demo/real rules) and returns only what the screen shows. Nothing
 * sensitive is selected: no IP addresses, no SNMP credentials, no user data.
 */
final class TvDashboardService
{
    /** The analytics slide covers the last 7 days. */
    private const ANALYTICS_RANGE = '7d';

    /** The analytics queries scan days of samples, so they are cached; the live data is not. */
    private const ANALYTICS_CACHE_SECONDS = 60;

    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly BandwidthAnalyticsService $analytics,
        private readonly MonitoringManager $monitoring,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $base = $this->dashboard->build('1h');
        $source = $this->monitoring->driverName();

        $alertCounts = $this->alertCounts($source);

        return [
            'generated_at' => $base['generated_at'],
            'campus_name' => (string) $this->settings->get('campus_name'),
            'timezone' => (string) $this->settings->get('timezone'),
            'overall' => $this->overall($base, $alertCounts),
            'mode' => $base['mode'],
            'capacity_mbps' => $base['capacity_mbps'],
            'thresholds' => $base['thresholds'],
            'collector' => $base['collector'],
            'latest_measurement_at' => $base['latest_measurement_at'],
            'wan' => [
                'configured' => $base['wan']['configured'],
                'current' => $base['wan']['current'],
            ],
            'summary' => $base['summary'] === null ? null : [
                'avg_in_mbps' => $base['summary']['avg_in_mbps'],
                'avg_out_mbps' => $base['summary']['avg_out_mbps'],
                'peak_in_mbps' => $base['summary']['peak_in_mbps'],
                'peak_out_mbps' => $base['summary']['peak_out_mbps'],
                'avg_utilization_percent' => $base['summary']['avg_utilization_percent'],
                'peak_utilization_percent' => $base['summary']['peak_utilization_percent'],
            ],
            'history' => array_map(static fn (array $point): array => [
                't' => $point['t'],
                'in_mbps' => $point['in_mbps'],
                'out_mbps' => $point['out_mbps'],
            ], $base['history']),
            'devices' => [
                ...$base['devices'],
                'list' => $this->devices($source),
            ],
            'alerts' => [
                ...$alertCounts,
                'list' => $this->openAlerts($source),
            ],
            'events' => $this->events($source),
            'analytics' => $this->analytics($source),
        ];
    }

    /**
     * Slide 2 of the wallboard. If the analytics cannot be built, the wallboard keeps
     * working and simply has no analytics slide (null), instead of failing as a whole.
     *
     * @return array<string, mixed>|null
     */
    private function analytics(string $source): ?array
    {
        try {
            $data = Cache::remember(
                'tv-display:analytics:'.$source.':'.self::ANALYTICS_RANGE,
                self::ANALYTICS_CACHE_SECONDS,
                fn (): array => $this->analytics->build(self::ANALYTICS_RANGE),
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return [
            'range' => $data['range'],
            'days' => $data['days'],
            'to' => $data['to'],
            'wan' => ['configured' => $data['wan']['configured']],
            'has_data' => $data['has_data'],
            'summary' => $data['summary'],
            'hourly' => $data['hourly'],
            'weekday' => $data['weekday'],
            'daily' => $data['daily'],
            'busiest' => $data['busiest'],
            'top_interfaces' => $data['top_interfaces'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function devices(string $source): array
    {
        return $this->dashboard->deviceQuery($source)
            ->where('is_enabled', true)
            ->orderBy('name')
            ->limit(60)
            // Only display fields are selected, so credentials and IP addresses never leave the database.
            ->get(['id', 'name', 'device_type', 'location', 'last_status', 'last_successful_poll_at'])
            ->map(fn ($device): array => [
                'id' => $device->id,
                'name' => $device->name,
                'type' => $device->device_type->label(),
                'location' => $device->location,
                'status' => $device->last_status->value,
                'last_successful_poll_at' => $device->last_successful_poll_at?->toIso8601String(),
            ])->all();
    }

    /**
     * @return array{active: int, critical: int, warning: int, unreachable: int, collector_failures: int}
     */
    private function alertCounts(string $source): array
    {
        $rows = $this->dashboard->alertQuery($source)
            ->open()
            ->selectRaw('severity, type, COUNT(*) as aggregate')
            ->groupBy('severity', 'type')
            ->toBase()
            ->get();

        $sum = static fn (callable $match): int => (int) $rows->filter($match)->sum('aggregate');

        return [
            'active' => $sum(static fn (object $row): bool => true),
            'critical' => $sum(static fn (object $row): bool => $row->severity === 'critical'),
            'warning' => $sum(static fn (object $row): bool => $row->severity === 'warning'),
            'unreachable' => $sum(static fn (object $row): bool => $row->type === AlertType::DeviceUnreachable->value),
            'collector_failures' => $sum(static fn (object $row): bool => in_array(
                $row->type,
                [AlertType::CollectorFailure->value, AlertType::NoRecentData->value],
                true,
            )),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openAlerts(string $source): array
    {
        return $this->dashboard->alertQuery($source)
            ->open()
            ->with(['device' => fn ($query) => $query->select('id', 'name')])
            ->latest('triggered_at')
            ->limit(20)
            ->get()
            ->map(fn (NetworkAlert $alert): array => $this->alertRow($alert))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(string $source): array
    {
        return $this->dashboard->alertQuery($source)
            ->with(['device' => fn ($query) => $query->select('id', 'name')])
            ->latest('triggered_at')
            ->limit(8)
            ->get()
            ->map(fn (NetworkAlert $alert): array => $this->alertRow($alert))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function alertRow(NetworkAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'title' => $alert->title,
            'severity' => $alert->severity->value,
            'status' => $alert->status->value,
            'type' => $alert->type instanceof AlertType ? $alert->type->value : (string) $alert->type,
            'device' => $alert->device?->name,
            'triggered_at' => $alert->triggered_at->toIso8601String(),
            'resolved_at' => $alert->resolved_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, int>  $alerts
     */
    private function overall(array $base, array $alerts): string
    {
        $collector = $base['collector']['status'];

        return match (true) {
            $collector === 'misconfigured' || $alerts['critical'] > 0 => 'critical',
            in_array($collector, ['no_devices', 'no_data'], true) => 'unknown',
            $collector === 'stale'
                || ($base['wan']['current']['stale'] ?? false)
                || $alerts['warning'] > 0
                || $base['devices']['unreachable'] > 0 => 'degraded',
            default => 'operational',
        };
    }
}