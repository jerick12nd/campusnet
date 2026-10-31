<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\MonitoredDevice;
use App\Monitoring\ConnectionTestResult;
use App\Monitoring\Drivers\SnmpMonitoringDriver;
use App\Services\SettingsService;
use Throwable;

/**
 * Stands in for the real SNMP driver in tests, so no packet is ever sent.
 */
final class FakeSnmpDriver extends SnmpMonitoringDriver
{
    public function __construct(private readonly ConnectionTestResult|Throwable $outcome)
    {
        parent::__construct(app(SettingsService::class));
    }

    public function testConnection(MonitoredDevice $device): ConnectionTestResult
    {
        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}