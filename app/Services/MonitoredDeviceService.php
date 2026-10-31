<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MonitoredDevice;
use App\Models\User;
use App\Monitoring\ConnectionTestResult;
use App\Monitoring\Exceptions\DriverUnavailableException;
use App\Monitoring\MonitoringManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Device lifecycle operations. Every change is written in a transaction together
 * with its audit record; audit metadata never contains credential values.
 */
class MonitoredDeviceService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MonitoringManager $monitoring,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated attributes, optionally with `credentials`
     */
    public function create(array $data, User $actor): MonitoredDevice
    {
        return DB::transaction(function () use ($data, $actor): MonitoredDevice {
            $device = new MonitoredDevice($data);
            $device->save();
            $device->refresh(); // load column defaults such as last_status = unknown

            $this->audit->record('device.created', $device, [
                'name' => $device->name,
                'management_ip' => $device->management_ip,
                'device_type' => $device->device_type->value,
                'snmp_version' => $device->snmp_version->value,
                'enabled' => $device->is_enabled,
            ], "Device {$device->name} created.", $actor);

            return $device;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated attributes; `credentials` only when replaced
     */
    public function update(MonitoredDevice $device, array $data, User $actor): MonitoredDevice
    {
        return DB::transaction(function () use ($device, $data, $actor): MonitoredDevice {
            $device->fill($data);
            $authChanged = array_key_exists('credentials', $data);

            $changes = [];
            foreach ($device->getDirty() as $field => $value) {
                if (in_array($field, ['credentials', 'updated_at'], true)) {
                    continue;
                }

                $changes[$field] = [
                    'from' => $this->forAudit($device->getRawOriginal($field)),
                    'to' => $this->forAudit($value),
                ];
            }

            $device->save();

            if ($changes !== [] || $authChanged) {
                $this->audit->record('device.updated', $device, [
                    'changes' => $changes,
                    'snmp_auth_changed' => $authChanged,
                ], "Device {$device->name} updated.", $actor);
            }

            return $device;
        });
    }

    public function setEnabled(MonitoredDevice $device, bool $enabled, User $actor): MonitoredDevice
    {
        if ($device->is_enabled === $enabled) {
            return $device;
        }

        return DB::transaction(function () use ($device, $enabled, $actor): MonitoredDevice {
            $device->forceFill(['is_enabled' => $enabled])->save();

            $this->audit->record(
                $enabled ? 'device.enabled' : 'device.disabled',
                $device,
                ['name' => $device->name],
                'Device '.$device->name.($enabled ? ' enabled.' : ' disabled.'),
                $actor,
            );

            return $device;
        });
    }

    /**
     * Soft delete. Interfaces and traffic history are kept; the foreign keys restrict
     * any hard delete while history exists.
     */
    public function archive(MonitoredDevice $device, User $actor): void
    {
        DB::transaction(function () use ($device, $actor): void {
            $device->delete();

            $this->audit->record('device.archived', $device, ['name' => $device->name], "Device {$device->name} archived.", $actor);
        });
    }

    /**
     * Test the device using only its stored address, with the active driver.
     * The device's status is updated only when the test actually learned something.
     */
    public function testConnection(MonitoredDevice $device, User $actor): ConnectionTestResult
    {
        try {
            $result = $this->monitoring->driver()->testConnection($device);
        } catch (DriverUnavailableException $e) {
            $result = ConnectionTestResult::notPerformed($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            $result = ConnectionTestResult::notPerformed('The connection test failed unexpectedly. Check the application log.');
        }

        if ($result->shouldPersist()) {
            $device->forceFill([
                'last_polled_at' => now(),
                'last_status' => $result->status,
                'last_successful_poll_at' => $result->success ? now() : $device->last_successful_poll_at,
                'last_error' => $result->success ? null : Str::limit($result->message, 500, ''),
            ])->save();
        }

        $this->audit->record('device.connection_tested', $device, [
            'driver' => $this->monitoring->driverName(),
            'success' => $result->success,
            'status' => $result->status?->value,
            'latency_ms' => $result->latencyMs,
            'simulated' => $result->simulated,
        ], "Connection test run for {$device->name}.", $actor);

        return $result;
    }

    private function forAudit(mixed $value): mixed
    {
        return is_string($value) ? Str::limit($value, 200) : $value;
    }
}