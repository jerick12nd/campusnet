<?php

declare(strict_types=1);

namespace App\Monitoring\Contracts;

use App\Enums\DataSource;
use App\Models\MonitoredDevice;
use App\Monitoring\ConnectionTestResult;

/**
 * A source of monitoring data. Phase 3 adds polling and interface discovery to this
 * contract; Phase 2 needs only the connection test.
 *
 * A driver must never fabricate a measurement for a real source, and must never
 * fall back to another driver when it fails.
 */
interface MonitoringDriver
{
    public function source(): DataSource;

    /**
     * Test the single device it is given, using only that device's stored address.
     */
    public function testConnection(MonitoredDevice $device): ConnectionTestResult;
}