<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityDataSource;
use App\Models\DeviceConnectionSession;
use App\Models\Member;
use App\Models\Office;
use App\Models\RegisteredDevice;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class OfficeConnectionService
{
    public const STATUSES = ['active', 'idle', 'no_devices', 'no_source'];

    /**
     * One row per office, built only from stored records.
     *
     * @return array{offices: list<array<string, mixed>>, totals: array<string, int>, source: array<string, mixed>}
     */
    public function overview(?string $search = null, ?string $status = null, ?int $officeId = null): array
    {
        $source = $this->sourceState();

        $offices = Office::query()
            ->when($officeId === null, fn (Builder $q) => $q->where('is_active', true))
            ->when($officeId !== null, fn (Builder $q) => $q->whereKey($officeId))
            ->when($search, function (Builder $q, string $term) {
                $q->where(fn (Builder $w) => $w
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('department', 'like', "%{$term}%"));
            })
            ->orderBy('name')
            ->get();

        $members = Member::query()->where('is_active', true)
            ->selectRaw('office_id, count(*) as total')
            ->groupBy('office_id')
            ->pluck('total', 'office_id');

        $registered = $this->registeredPerOffice();

        $online = DeviceConnectionSession::online()
            ->whereNotNull('office_id')
            ->whereNotNull('registered_device_id')
            ->selectRaw('office_id, count(distinct registered_device_id) as devices, sum(rx_bytes) as rx, sum(tx_bytes) as tx, count(rx_bytes) as measured')
            ->groupBy('office_id')
            ->get()
            ->keyBy('office_id');

        $lastSeen = DeviceConnectionSession::query()
            ->whereNotNull('office_id')
            ->selectRaw('office_id, max(last_seen_at) as seen')
            ->groupBy('office_id')
            ->pluck('seen', 'office_id');

        $rows = $offices->map(function (Office $office) use ($source, $members, $registered, $online, $lastSeen) {
            $onlineRow = $online->get($office->id);
            $registeredCount = (int) ($registered[$office->id] ?? 0);
            $onlineCount = (int) ($onlineRow?->devices ?? 0);
            $measured = (int) ($onlineRow?->measured ?? 0) > 0;
            $seen = $lastSeen[$office->id] ?? null;

            $status = match (true) {
                ! $source['enabled'] => 'no_source',
                $registeredCount === 0 => 'no_devices',
                $onlineCount > 0 => 'active',
                default => 'idle',
            };

            return [
                'id' => $office->id,
                'name' => $office->name,
                'code' => $office->code,
                'department' => $office->department,
                'subnet_cidr' => $office->subnet_cidr,
                'description' => $office->description,
                'members' => (int) ($members[$office->id] ?? 0),
                'registered_devices' => $registeredCount,
                'online_devices' => $onlineCount,
                'offline_devices' => max(0, $registeredCount - $onlineCount),
                'status' => $status,
                'last_seen_at' => $seen ? Carbon::parse($seen)->toIso8601String() : null,
                // Only filled when a source really measured per-client bytes. DHCP does not.
                'rx_bytes' => $measured ? (int) $onlineRow->rx : null,
                'tx_bytes' => $measured ? (int) $onlineRow->tx : null,
            ];
        });

        $totals = [
            'offices' => $rows->count(),
            'online_devices' => (int) $rows->sum('online_devices'),
            'registered_devices' => (int) $rows->sum('registered_devices'),
            'unidentified_online' => DeviceConnectionSession::online()
                ->whereNull('registered_device_id')
                ->distinct()
                ->count('mac_address'),
        ];

        if ($status) {
            $rows = $rows->where('status', $status);
        }

        return ['offices' => $rows->values()->all(), 'totals' => $totals, 'source' => $source];
    }

    /** Sessions of one office. state: online (now), history (not online) or all. */
    public function connections(Office $office, string $state): Builder
    {
        $threshold = $this->threshold();

        $query = DeviceConnectionSession::query()
            ->where('office_id', $office->id)
            ->with(['registeredDevice:id,name,device_type', 'member:id,full_name']);

        return match ($state) {
            'online' => $query->online(),
            'history' => $query->where(fn (Builder $q) => $q
                ->whereNotNull('ended_at')
                ->orWhere('last_seen_at', '<', $threshold)),
            default => $query,
        };
    }

    /** @return array<string, mixed> */
    public function present(DeviceConnectionSession $session): array
    {
        return [
            'id' => $session->id,
            'device_name' => $session->registeredDevice?->name,
            'device_type' => $session->registeredDevice?->device_type?->label(),
            'hostname' => $session->hostname,
            'member_name' => $session->member?->full_name,
            'ip_address' => $session->ip_address,
            'mac_address' => $session->mac_address,
            'interface_name' => $session->interface_name,
            'started_at' => $session->started_at->toIso8601String(),
            'last_seen_at' => $session->last_seen_at->toIso8601String(),
            'status' => $session->ended_at === null && $session->last_seen_at->gte($this->threshold())
                ? 'online'
                : 'offline',
        ];
    }

    /** @return array{enabled: bool, last_run_at: ?string, stale: bool, failing: bool} */
    public function sourceState(): array
    {
        $sources = ActivityDataSource::enabled()->where('type', 'dhcp')->get();
        $lastRun = $sources->max('last_run_at');
        $enabled = $sources->isNotEmpty();

        return [
            'enabled' => $enabled,
            'last_run_at' => $lastRun?->toIso8601String(),
            'stale' => $enabled && ($lastRun === null || $lastRun->lt($this->threshold())),
            'failing' => $sources->contains('last_status', 'failed'),
        ];
    }

    /**
     * Registered devices per office. A device belongs to the office of the member it is
     * assigned to; a shared device (printer) belongs to the office set on the device.
     *
     * @return \Illuminate\Support\Collection<int|string, int>
     */
    private function registeredPerOffice()
    {
        return RegisteredDevice::query()
            ->where('registered_devices.is_active', true)
            ->leftJoin('member_device_assignments as a', function ($join) {
                $join->on('a.registered_device_id', '=', 'registered_devices.id')
                    ->whereNull('a.unassigned_at');
            })
            ->leftJoin('members as m', 'm.id', '=', 'a.member_id')
            ->selectRaw('coalesce(m.office_id, registered_devices.office_id) as effective_office_id, count(*) as total')
            ->groupBy('effective_office_id')
            ->pluck('total', 'effective_office_id');
    }

    /**
     * Typed as the interface because this app's now() returns an immutable Carbon.
     */
    private function threshold(): CarbonInterface
    {
        return now()->subSeconds((int) config('office_network.online_threshold_seconds'));
    }
}