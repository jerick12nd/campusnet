<?php

declare(strict_types=1);

namespace Tests\Feature\OfficeNetwork;

use App\Models\ActivityDataSource;
use App\Models\DeviceConnectionSession;
use App\Models\Member;
use App\Models\MemberDeviceAssignment;
use App\Models\Office;
use App\Models\RegisteredDevice;
use App\Models\User;

/**
 * Small builders so each test reads as a story about one office.
 * Everything is written to the real tables; nothing is mocked.
 */
trait BuildsOfficeNetwork
{
    private int $macCounter = 0;

    protected function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /** @param array<string, mixed> $attributes */
    protected function makeOffice(string $name = 'CIDS Office', array $attributes = []): Office
    {
        return Office::create(['name' => $name, 'is_active' => true, ...$attributes]);
    }

    protected function makeMember(Office $office, string $name = 'Maria Santos', bool $active = true): Member
    {
        return Member::create(['office_id' => $office->id, 'full_name' => $name, 'is_active' => $active]);
    }

    /** A registered device with an open assignment to the member (that is how its office is found). */
    protected function makeDevice(Member $member, string $name = 'Laptop'): RegisteredDevice
    {
        $device = RegisteredDevice::create([
            'name' => $name,
            'device_type' => 'laptop',
            'mac_address' => $this->nextMac(),
        ]);

        MemberDeviceAssignment::create([
            'registered_device_id' => $device->id,
            'member_id' => $member->id,
            'assigned_at' => now(),
        ]);

        return $device;
    }

    /**
     * Records a connection session. Pass null for a device that is not registered.
     * Default: open and seen 30 seconds ago, so it counts as online.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function connect(?RegisteredDevice $device, array $attributes = []): DeviceConnectionSession
    {
        $device?->loadMissing('currentAssignment.member');
        $member = $device?->currentAssignment?->member;

        return DeviceConnectionSession::create([
            'registered_device_id' => $device?->id,
            'member_id' => $member?->id,
            'office_id' => $member?->office_id,
            'mac_address' => $device?->mac_address ?? $this->nextMac(),
            'ip_address' => '192.168.10.'.(10 + $this->macCounter),
            'started_at' => now()->subHour(),
            'last_seen_at' => now()->subSeconds(30),
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function enableDhcpSource(array $attributes = []): ActivityDataSource
    {
        return ActivityDataSource::create([
            'key' => 'mikrotik_dhcp',
            'name' => 'MikroTik DHCP leases',
            'type' => 'dhcp',
            'is_enabled' => true,
            'last_run_at' => now(),
            'last_status' => 'ok',
            ...$attributes,
        ]);
    }

    private function nextMac(): string
    {
        return sprintf('AA:BB:CC:00:00:%02X', ++$this->macCounter);
    }
}