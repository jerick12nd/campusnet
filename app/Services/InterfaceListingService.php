<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InterfaceRole;
use App\Enums\SampleStatus;
use App\Models\MonitoredDevice;
use App\Models\NetworkInterface;
use App\Monitoring\MonitoringManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The interface inventory. Same honesty rules as the dashboard: demo devices are hidden
 * unless the mock driver is active, archived devices never appear, and the "latest
 * traffic" of a row comes only from a stored, valid sample of the active driver.
 */
final class InterfaceListingService
{
    public const PER_PAGE = 20;

    public function __construct(private readonly MonitoringManager $monitoring) {}

    /**
     * @param  array{search: string, device: string, role: string, monitored: string, status: string}  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters): array
    {
        $source = $this->monitoring->driverName();

        $query = $this->base($source)
            ->with('device:id,name,is_demo')
            ->select('network_interfaces.*');

        $this->applyFilters($query, $filters);

        $page = $query
            ->orderBy('monitored_devices.name')
            ->orderBy('network_interfaces.if_index')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $latest = $this->latestRates($page->pluck('id')->all(), $source);

        $page->through(function (NetworkInterface $interface) use ($latest): array {
            $reading = $latest->get($interface->id);

            return [
                'id' => $interface->id,
                'if_index' => $interface->if_index,
                'name' => $interface->name,
                'description' => $interface->description,
                'role' => ['value' => $interface->role->value, 'label' => $interface->role->label()],
                'oper_status' => $interface->oper_status,
                'speed_mbps' => $interface->speed_mbps,
                'is_monitored' => $interface->is_monitored,
                'last_polled_at' => $interface->last_polled_at?->toIso8601String(),
                'device' => [
                    'id' => $interface->device->id,
                    'name' => $interface->device->name,
                    'is_demo' => $interface->device->is_demo,
                ],
                'latest' => $reading === null ? null : [
                    'in_mbps' => round((float) $reading->in_mbps, 2),
                    'out_mbps' => round((float) $reading->out_mbps, 2),
                    'sampled_at' => CarbonImmutable::parse($reading->sampled_at, 'UTC')->toIso8601String(),
                ],
            ];
        });

        return [
            'interfaces' => $page->toArray(),
            'stats' => $this->stats($source),
            'devices' => $this->deviceOptions($source),
            'roles' => array_map(
                fn (InterfaceRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                InterfaceRole::cases(),
            ),
        ];
    }

    /**
     * Interfaces of devices that are visible in the active mode.
     *
     * @return Builder<NetworkInterface>
     */
    private function base(string $source): Builder
    {
        return NetworkInterface::query()
            ->join('monitored_devices', 'monitored_devices.id', '=', 'network_interfaces.monitored_device_id')
            ->whereNull('monitored_devices.deleted_at')
            ->when($source !== 'mock', fn (Builder $query) => $query->where('monitored_devices.is_demo', false));
    }

    /**
     * @param  Builder<NetworkInterface>  $query
     * @param  array{search: string, device: string, role: string, monitored: string, status: string}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] !== '', function (Builder $q) use ($filters): void {
                $term = '%'.$filters['search'].'%';

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('network_interfaces.name', 'like', $term)
                        ->orWhere('network_interfaces.description', 'like', $term)
                        ->orWhere('monitored_devices.name', 'like', $term);
                });
            })
            ->when($filters['device'] !== '', fn (Builder $q) => $q->where('network_interfaces.monitored_device_id', (int) $filters['device']))
            ->when($filters['role'] !== '', fn (Builder $q) => $q->where('network_interfaces.role', $filters['role']))
            ->when($filters['monitored'] !== '', fn (Builder $q) => $q->where('network_interfaces.is_monitored', $filters['monitored'] === 'yes'))
            ->when($filters['status'] === 'up', fn (Builder $q) => $q->where('network_interfaces.oper_status', 'up'))
            ->when($filters['status'] === 'down', fn (Builder $q) => $q->where('network_interfaces.oper_status', 'down'))
            ->when($filters['status'] === 'unknown', function (Builder $q): void {
                $q->where(function (Builder $inner): void {
                    $inner->whereNull('network_interfaces.oper_status')
                        ->orWhereNotIn('network_interfaces.oper_status', ['up', 'down']);
                });
            });
    }

    /**
     * @return array{total: int, monitored: int, up: int, down: int}
     */
    private function stats(string $source): array
    {
        $row = $this->base($source)
            ->toBase()
            ->selectRaw(
                'COUNT(*) as total, '
                .'SUM(CASE WHEN network_interfaces.is_monitored = 1 THEN 1 ELSE 0 END) as monitored, '
                ."SUM(CASE WHEN network_interfaces.oper_status = 'up' THEN 1 ELSE 0 END) as up_count, "
                ."SUM(CASE WHEN network_interfaces.oper_status = 'down' THEN 1 ELSE 0 END) as down_count"
            )
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'monitored' => (int) ($row->monitored ?? 0),
            'up' => (int) ($row->up_count ?? 0),
            'down' => (int) ($row->down_count ?? 0),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function deviceOptions(string $source): array
    {
        return MonitoredDevice::query()
            ->when($source !== 'mock', fn (Builder $query) => $query->where('is_demo', false))
            ->whereHas('interfaces')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (MonitoredDevice $device): array => ['id' => $device->id, 'name' => $device->name])
            ->all();
    }

    /**
     * The newest valid sample of each given interface, keyed by interface id.
     *
     * @param  list<int>  $interfaceIds
     * @return Collection<int, object{network_interface_id: int, in_mbps: string, out_mbps: string, sampled_at: string}>
     */
    private function latestRates(array $interfaceIds, string $source): Collection
    {
        if ($interfaceIds === []) {
            return collect();
        }

        $newest = DB::table('traffic_samples')
            ->selectRaw('network_interface_id, MAX(sampled_at) as max_at')
            ->whereIn('network_interface_id', $interfaceIds)
            ->where('source', $source)
            ->where('status', SampleStatus::Ok->value)
            ->groupBy('network_interface_id');

        return DB::table('traffic_samples as s')
            ->joinSub($newest, 'm', fn ($join) => $join
                ->on('m.network_interface_id', '=', 's.network_interface_id')
                ->on('m.max_at', '=', 's.sampled_at'))
            ->where('s.source', $source)
            ->where('s.status', SampleStatus::Ok->value)
            ->get(['s.network_interface_id', 's.in_mbps', 's.out_mbps', 's.sampled_at'])
            ->keyBy('network_interface_id');
    }
}