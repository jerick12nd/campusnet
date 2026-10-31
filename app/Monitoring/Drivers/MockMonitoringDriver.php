<?php

declare(strict_types=1);

namespace App\Monitoring\Drivers;

use App\Enums\DataSource;
use App\Models\MonitoredDevice;
use App\Monitoring\ConnectionTestResult;
use App\Monitoring\Contracts\MonitoringDriver;

/**
 * Simulated driver for development and demonstrations. Everything it produces is
 * labelled simulated; it never touches the network and never changes device status.
 */
class MockMonitoringDriver implements MonitoringDriver
{
    public function source(): DataSource
    {
        return DataSource::Mock;
    }

    public function testConnection(MonitoredDevice $device): ConnectionTestResult
    {
        return ConnectionTestResult::demo(
            'DEMO MODE: this is a simulated check. No packet was sent to the device, so its status was not changed. '
            .'Set BANDWIDTH_MONITORING_DRIVER=snmp to test real equipment.'
        );
    }
}