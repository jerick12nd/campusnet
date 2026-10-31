<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\DataSource;
use App\Models\MonitoredDevice;
use App\Monitoring\ConnectionTestResult;
use App\Monitoring\Contracts\PollingDriver;
use App\Monitoring\DiscoveredInterface;
use App\Monitoring\Exceptions\PollFailedException;
use App\Monitoring\InterfaceCounters;

final class FakePollingDriver implements PollingDriver
{
    /** @var list<InterfaceCounters> */
    public array $counters = [];

    /** @var list<DiscoveredInterface> */
    public array $discovered = [];

    public ?string $failWith = null;

    public function source(): DataSource
    {
        return DataSource::Snmp;
    }

    public function testConnection(MonitoredDevice $device): ConnectionTestResult
    {
        return ConnectionTestResult::notPerformed('Not used in these tests.');
    }

    public function readCounters(MonitoredDevice $device): array
    {
        if ($this->failWith !== null) {
            throw new PollFailedException($this->failWith);
        }

        return $this->counters;
    }

    public function discoverInterfaces(MonitoredDevice $device): array
    {
        return $this->discovered;
    }
}