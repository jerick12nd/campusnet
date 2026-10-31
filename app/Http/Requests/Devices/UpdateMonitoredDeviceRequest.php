<?php

declare(strict_types=1);

namespace App\Http\Requests\Devices;

use App\Models\MonitoredDevice;

class UpdateMonitoredDeviceRequest extends MonitoredDeviceRequest
{
    /**
     * Credentials are only required when they cannot be kept: the SNMP version changed
     * (the old secrets would not fit), none are stored yet, or the person is replacing them.
     */
    protected function credentialsNeeded(): bool
    {
        $device = $this->device();

        return $this->input('snmp_version') !== $device->snmp_version->value
            || ! $device->hasCredentials()
            || $this->hasSecretInput();
    }

    protected function ignoreId(): ?int
    {
        return $this->device()->id;
    }

    private function device(): MonitoredDevice
    {
        $device = $this->route('device');

        return $device instanceof MonitoredDevice ? $device : MonitoredDevice::findOrFail($device);
    }
}