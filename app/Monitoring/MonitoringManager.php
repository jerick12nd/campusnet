<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Monitoring\Contracts\MonitoringDriver;
use App\Monitoring\Drivers\MockMonitoringDriver;
use App\Monitoring\Drivers\SnmpMonitoringDriver;
use App\Monitoring\Exceptions\DriverUnavailableException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the active driver from BANDWIDTH_MONITORING_DRIVER. There is deliberately
 * no fallback: if the configured driver cannot be used, callers get an exception
 * instead of silently receiving a different (e.g. simulated) driver.
 */
class MonitoringManager
{
    public function __construct(private readonly Container $container) {}

    public function driverName(): string
    {
        return (string) config('campusnet.driver', 'mock');
    }

    public function isDemo(): bool
    {
        return $this->driverName() === 'mock';
    }

    public function isKnownDriver(): bool
    {
        return in_array($this->driverName(), (array) config('campusnet.drivers', []), true);
    }

    /**
     * @throws DriverUnavailableException
     */
    public function driver(): MonitoringDriver
    {
        return match ($this->driverName()) {
            'mock' => $this->container->make(MockMonitoringDriver::class),
            'snmp' => $this->container->make(SnmpMonitoringDriver::class),
            'mikrotik' => throw new DriverUnavailableException('The MikroTik RouterOS driver is not available yet. Use BANDWIDTH_MONITORING_DRIVER=snmp for real devices.'),
            default => throw new DriverUnavailableException('BANDWIDTH_MONITORING_DRIVER has an unknown value. Use mock or snmp.'),
        };
    }
}