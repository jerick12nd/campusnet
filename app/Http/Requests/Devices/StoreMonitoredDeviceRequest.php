<?php

declare(strict_types=1);

namespace App\Http\Requests\Devices;

class StoreMonitoredDeviceRequest extends MonitoredDeviceRequest
{
    protected function credentialsNeeded(): bool
    {
        return true;
    }

    protected function ignoreId(): ?int
    {
        return null;
    }
}