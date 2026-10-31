<?php

declare(strict_types=1);

namespace App\Monitoring\Contracts;

use App\Models\MonitoredDevice;
use App\Monitoring\DiscoveredInterface;
use App\Monitoring\Exceptions\PollFailedException;
use App\Monitoring\InterfaceCounters;

/**
 * A driver that can read live counters. Kept separate from MonitoringDriver so a
 * driver that can only test connections is never mistaken for one that can poll.
 */
interface PollingDriver extends MonitoringDriver
{
    /**
     * Current octet counters of the device's interfaces.
     *
     * @return list<InterfaceCounters>
     *
     * @throws PollFailedException when the device cannot be read (timeout, auth error, ...)
     */
    public function readCounters(MonitoredDevice $device): array;

    /**
     * Interfaces the device reports.
     *
     * @return list<DiscoveredInterface>
     *
     * @throws PollFailedException
     */
    public function discoverInterfaces(MonitoredDevice $device): array;
}