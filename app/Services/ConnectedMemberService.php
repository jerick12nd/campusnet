<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DeviceConnectionSession;
use App\Models\Member;
use App\Models\RegisteredDevice;
use App\Support\MacAddress;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class ConnectedMemberService
{
    public const STATUSES = ['online', 'offline', 'unidentified'];

    /**
     * One row per device (MAC address): its latest connection.
     *
     * @param  array{search?: ?string, office?: ?int, status?: ?string, type?: ?string}  $filters
     */
    public function query(array $filters): Builder
    {
        $threshold = $this->threshold();
        $latestPerMac = DeviceConnectionSession::query()->selectRaw('max(id)')->groupBy('mac_address');

        return DeviceConnectionSession::query()
            ->whereIn('id', $latestPerMac)
            ->with($this->relations())
            ->when($filters['office'] ?? null, fn (Builder $q, int $office) => $q->where('office_id', $office))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->whereHas(
                'registeredDevice',
                fn (Builder $d) => $d->where('device_type', $type),
            ))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => match ($status) {
                'online' => $q->online(),
                'offline' => $q->whereNotNull('registered_device_id')->where(fn (Builder $w) => $w
                    ->whereNotNull('ended_at')
                    ->orWhere('last_seen_at', '<', $threshold)),
                'unidentified' => $q->whereNull('registered_device_id'),
                default => $q,
            })
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w
                ->whereHas('member', fn (Builder $m) => $m->where('full_name', 'like', "%{$term}%"))
                ->orWhereHas('registeredDevice', fn (Builder $d) => $d->where('name', 'like', "%{$term}%"))
                ->orWhere('hostname', 'like', "%{$term}%")
                ->orWhere('ip_address', 'like', "%{$term}%")
                ->orWhere('mac_address', 'like', "%{$term}%")));
    }

    /** Eager loads that also work for archived offices, members and devices (history stays readable). */
    public function relations(): array
    {
        return [
            'registeredDevice' => fn ($q) => $q->withTrashed()->select('id', 'name', 'device_type'),
            'member' => fn ($q) => $q->withTrashed()->select('id', 'full_name'),
            'office' => fn ($q) => $q->withTrashed()->select('id', 'name'),
        ];
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        return [
            'online_identified' => DeviceConnectionSession::online()
                ->whereNotNull('registered_device_id')->distinct()->count('registered_device_id'),
            'online_unidentified' => DeviceConnectionSession::online()
                ->whereNull('registered_device_id')->distinct()->count('mac_address'),
            'members' => Member::query()->where('is_active', true)->count(),
            'registered_devices' => RegisteredDevice::query()->where('is_active', true)->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function present(DeviceConnectionSession $session): array
    {
        return [
            'id' => $session->id,
            'identified' => $session->registered_device_id !== null,
            'member_id' => $session->member_id,
            'member_name' => $session->member?->full_name,
            'office_id' => $session->office_id,
            'office_name' => $session->office?->name,
            'device_name' => $session->registeredDevice?->name,
            'device_type' => $session->registeredDevice?->device_type?->label(),
            'hostname' => $session->hostname,
            'mac_address' => $session->mac_address,
            'private_mac' => MacAddress::isLocallyAdministered($session->mac_address),
            'ip_address' => $session->ip_address,
            'interface_name' => $session->interface_name,
            'started_at' => $session->started_at->toIso8601String(),
            'last_seen_at' => $session->last_seen_at->toIso8601String(),
            'status' => $session->ended_at === null && $session->last_seen_at->gte($this->threshold())
                ? 'online'
                : 'offline',
        ];
    }

    private function threshold(): CarbonInterface
    {
        return now()->subSeconds((int) config('office_network.online_threshold_seconds'));
    }
}