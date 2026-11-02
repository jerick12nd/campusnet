<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Models\MonitoredDevice;
use App\Models\NetworkAlert;
use App\Monitoring\MonitoringManager;
use Illuminate\Database\Eloquent\Builder;

/**
 * The alert list. Same visibility rule as the dashboard: alerts of demo devices are
 * hidden unless the mock driver is active, and alerts of archived devices are not listed.
 * System alerts (no device) are always visible.
 */
final class AlertListingService
{
    public const PER_PAGE = 20;

    public function __construct(private readonly MonitoringManager $monitoring) {}

    /**
     * Every alert the current mode may show.
     *
     * @return Builder<NetworkAlert>
     */
    public function visible(?string $source = null): Builder
    {
        $source ??= $this->monitoring->driverName();

        return NetworkAlert::query()->where(function (Builder $query) use ($source): void {
            $query->whereNull('monitored_device_id')
                ->orWhereHas('device', fn (Builder $device) => $device
                    ->when($source !== 'mock', fn (Builder $q) => $q->where('is_demo', false)));
        });
    }

    /**
     * @param  array{status: string, severity: string, type: string, device: string, search: string}  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters): array
    {
        $source = $this->monitoring->driverName();

        $query = $this->visible($source)->with([
            'device:id,name,is_demo',
            'networkInterface:id,name',
            'acknowledgedBy:id,name',
            'resolvedBy:id,name',
        ]);

        $term = '%'.$filters['search'].'%';

        $query
            ->when($filters['status'] === 'open', fn (Builder $q) => $q->open())
            ->when(
                in_array($filters['status'], ['active', 'acknowledged', 'resolved'], true),
                fn (Builder $q) => $q->where('status', $filters['status']),
            )
            ->when($filters['severity'] !== '', fn (Builder $q) => $q->where('severity', $filters['severity']))
            ->when($filters['type'] !== '', fn (Builder $q) => $q->where('type', $filters['type']))
            ->when($filters['device'] !== '', fn (Builder $q) => $q->where('monitored_device_id', (int) $filters['device']))
            ->when($filters['search'] !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('title', 'like', $term)
                ->orWhere('description', 'like', $term)));

        // Open alerts first, the most severe first, then the newest.
        $page = $query
            ->orderByRaw("CASE WHEN status = 'resolved' THEN 1 ELSE 0 END")
            ->orderByRaw("CASE severity WHEN 'critical' THEN 3 WHEN 'warning' THEN 2 ELSE 1 END DESC")
            ->orderByDesc('triggered_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $page->through(fn (NetworkAlert $alert): array => [
            'id' => $alert->id,
            'type' => ['value' => $alert->type->value, 'label' => $alert->type->label()],
            'severity' => $alert->severity->value,
            'status' => $alert->status->value,
            'title' => $alert->title,
            'description' => $alert->description,
            'device' => $alert->device === null ? null : [
                'id' => $alert->device->id,
                'name' => $alert->device->name,
                'is_demo' => $alert->device->is_demo,
            ],
            'interface' => $alert->networkInterface?->name,
            'triggered_at' => $alert->triggered_at->toIso8601String(),
            'last_seen_at' => $alert->last_seen_at?->toIso8601String(),
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            'acknowledged_by' => $alert->acknowledgedBy?->name,
            'resolved_at' => $alert->resolved_at?->toIso8601String(),
            'resolved_by' => $alert->resolvedBy?->name,
            'resolution_note' => $alert->resolution_note,
            'auto_resolved' => $alert->resolved_at !== null && $alert->resolved_by === null,
        ]);

        return [
            'alerts' => $page->toArray(),
            'stats' => $this->stats($source),
            'devices' => $this->deviceOptions($source),
            'types' => array_map(fn (AlertType $type): array => ['value' => $type->value, 'label' => $type->label()], AlertType::cases()),
            'severities' => array_map(fn (AlertSeverity $severity): array => ['value' => $severity->value, 'label' => $severity->label()], AlertSeverity::cases()),
        ];
    }

    /**
     * @return array{open: int, active: int, acknowledged: int, critical: int, resolved_7d: int}
     */
    private function stats(string $source): array
    {
        $rows = $this->visible($source)
            ->toBase()
            ->selectRaw('status, severity, COUNT(*) as aggregate')
            ->groupBy('status', 'severity')
            ->get();

        $active = 0;
        $acknowledged = 0;
        $critical = 0;

        foreach ($rows as $row) {
            $count = (int) $row->aggregate;

            if ($row->status === AlertStatus::Active->value) {
                $active += $count;
            } elseif ($row->status === AlertStatus::Acknowledged->value) {
                $acknowledged += $count;
            }

            if ($row->status !== AlertStatus::Resolved->value && $row->severity === AlertSeverity::Critical->value) {
                $critical += $count;
            }
        }

        // "Device recovered" events are created already resolved, so they are not counted as resolved problems.
        $resolved = $this->visible($source)
            ->where('status', AlertStatus::Resolved->value)
            ->where('type', '!=', AlertType::DeviceRecovered->value)
            ->where('resolved_at', '>=', now()->subDays(7))
            ->count();

        return [
            'open' => $active + $acknowledged,
            'active' => $active,
            'acknowledged' => $acknowledged,
            'critical' => $critical,
            'resolved_7d' => $resolved,
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function deviceOptions(string $source): array
    {
        return MonitoredDevice::query()
            ->when($source !== 'mock', fn (Builder $query) => $query->where('is_demo', false))
            ->whereIn('id', NetworkAlert::query()->whereNotNull('monitored_device_id')->select('monitored_device_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (MonitoredDevice $device): array => ['id' => $device->id, 'name' => $device->name])
            ->all();
    }
}