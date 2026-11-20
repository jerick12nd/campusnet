<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DeviceConnectionSession;
use App\Models\Member;
use App\Models\MemberDeviceAssignment;
use App\Models\RegisteredDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place that links devices to members. A device belongs to a member only
 * because an administrator said so; nothing here ever guesses from an IP address.
 */
class MemberAssignmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function register(array $data, User $actor): RegisteredDevice
    {
        return DB::transaction(function () use ($data, $actor) {
            $device = RegisteredDevice::create($data);

            // Connections that are open right now were recorded as unidentified: link them.
            // Closed sessions stay as they were recorded.
            DeviceConnectionSession::query()
                ->whereNull('registered_device_id')
                ->whereNull('ended_at')
                ->where('mac_address', $device->mac_address)
                ->update(['registered_device_id' => $device->id, 'office_id' => $device->office_id]);

            $this->audit->record(
                action: 'device.registered',
                subject: $device,
                description: "Device {$device->name} registered.",
                metadata: ['device_type' => $device->device_type?->value, 'office_id' => $device->office_id],
                actor: $actor,
            );

            return $device;
        });
    }

    public function assign(RegisteredDevice $device, Member $member, User $actor): MemberDeviceAssignment
    {
        return DB::transaction(function () use ($device, $member, $actor) {
            // Lock the device row so two administrators cannot open two assignments at once.
            RegisteredDevice::query()->whereKey($device->id)->lockForUpdate()->first();

            $current = MemberDeviceAssignment::query()
                ->where('registered_device_id', $device->id)
                ->whereNull('unassigned_at')
                ->first();

            if ($current !== null && $current->member_id === $member->id) {
                return $current;
            }

            // Close the old row and open a new one: who used the device is never lost.
            $current?->update(['unassigned_at' => now()]);

            $assignment = MemberDeviceAssignment::create([
                'registered_device_id' => $device->id,
                'member_id' => $member->id,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);

            $this->syncOpenSessions($device, $member);

            $this->audit->record(
                action: 'device.assigned',
                subject: $device,
                description: "Device {$device->name} assigned to {$member->full_name}.",
                metadata: ['member_id' => $member->id, 'previous_member_id' => $current?->member_id],
                actor: $actor,
            );

            return $assignment;
        });
    }

    /** Returns false when the device had no open assignment. */
    public function unassign(RegisteredDevice $device, User $actor): bool
    {
        return DB::transaction(function () use ($device, $actor) {
            $current = MemberDeviceAssignment::query()
                ->where('registered_device_id', $device->id)
                ->whereNull('unassigned_at')
                ->with(['member' => fn ($q) => $q->withTrashed()])
                ->first();

            if ($current === null) {
                return false;
            }

            $current->update(['unassigned_at' => now()]);
            $this->syncOpenSessions($device, null);

            $this->audit->record(
                action: 'device.unassigned',
                subject: $device,
                description: "Device {$device->name} unassigned from {$current->member?->full_name}.",
                metadata: ['member_id' => $current->member_id],
                actor: $actor,
            );

            return true;
        });
    }

    /** Re-applies the device's current owner (or its own office) to its open connections. */
    public function syncDevice(RegisteredDevice $device): void
    {
        $member = MemberDeviceAssignment::query()
            ->where('registered_device_id', $device->id)
            ->whereNull('unassigned_at')
            ->with('member')
            ->first()?->member;

        $this->syncOpenSessions($device, $member);
    }

    /** A member moved to another office: their open connections move with them. */
    public function syncMemberOffice(Member $member): void
    {
        DeviceConnectionSession::query()
            ->where('member_id', $member->id)
            ->whereNull('ended_at')
            ->update(['office_id' => $member->office_id]);
    }

    /** Only open connections change. Closed ones keep who was assigned when they happened. */
    private function syncOpenSessions(RegisteredDevice $device, ?Member $member): void
    {
        DeviceConnectionSession::query()
            ->where('registered_device_id', $device->id)
            ->whereNull('ended_at')
            ->update([
                'member_id' => $member?->id,
                'office_id' => $member?->office_id ?? $device->office_id,
            ]);
    }
}