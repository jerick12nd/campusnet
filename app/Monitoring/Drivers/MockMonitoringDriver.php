<?php

declare(strict_types=1);

namespace App\Monitoring\Drivers;

use App\Enums\DataSource;
use App\Enums\InterfaceRole;
use App\Models\MonitoredDevice;
use App\Monitoring\ConnectionTestResult;
use App\Monitoring\Contracts\PollingDriver;
use App\Monitoring\Exceptions\PollFailedException;
use App\Monitoring\InterfaceCounters;
use App\Services\SettingsService;
use App\Support\DemoTraffic;
use DateTimeZone;

/**
 * Simulated driver for development and demonstrations. Everything it produces is
 * labelled simulated; it never touches the network.
 */
class MockMonitoringDriver implements PollingDriver
{
    public function __construct(private readonly SettingsService $settings) {}

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

    public function discoverInterfaces(MonitoredDevice $device): array
    {
        // The simulated topology comes from campusnet:demo-data; the mock never invents interfaces.
        return [];
    }

    public function readCounters(MonitoredDevice $device): array
    {
        // Keeps the simulated outage created by campusnet:demo-data going.
        if (str_starts_with((string) $device->last_error, '[DEMO]')) {
            throw new PollFailedException('[DEMO] Simulated outage: no response from the device.');
        }

        $now = now()->toImmutable();
        $capacity = (float) $this->settings->get('bandwidth_capacity_mbps');
        $local = $now->setTimezone($this->timezone((string) $this->settings->get('timezone')));
        $load = DemoTraffic::loadFactor((int) $local->format('G'), (int) $local->format('N'));

        $readings = [];

        foreach ($device->interfaces()->monitored()->get() as $interface) {
            $elapsed = $interface->last_polled_at === null
                ? 0
                : max(0, min(900, $now->getTimestamp() - $interface->last_polled_at->getTimestamp()));

            [$in, $out] = $this->rates($interface->role, $interface->name, $capacity, $load);

            $readings[] = new InterfaceCounters(
                ifIndex: $interface->if_index,
                inOctets: (int) ($interface->last_in_octets ?? 0) + $this->octets($in, $elapsed),
                outOctets: (int) ($interface->last_out_octets ?? 0) + $this->octets($out, $elapsed),
                operStatus: 'up',
                speedMbps: $interface->speed_mbps,
                counterBits: 64,
            );
        }

        return $readings;
    }

    /**
     * @return array{0: float, 1: float} inbound and outbound Mbps
     */
    private function rates(InterfaceRole $role, string $name, float $capacity, float $load): array
    {
        $noise = 0.85 + 0.30 * (mt_rand() / mt_getrandmax());
        $ratio = mt_rand() / mt_getrandmax();

        if ($role === InterfaceRole::Wan) {
            $in = $capacity * (0.06 + 0.84 * $load) * $noise;
            $out = $in * (0.22 + 0.15 * $ratio);
        } else {
            // A stable share per interface, so each port keeps its own character between polls.
            $weight = 0.2 + (crc32($name) % 60) / 100;
            $in = $capacity * $weight * (0.02 + 0.40 * $load) * $noise;
            $out = $in * (0.55 + 0.25 * $ratio);
        }

        return [min($in, $capacity * 1.2), min($out, $capacity * 1.2)];
    }

    private function octets(float $mbps, int $seconds): int
    {
        return (int) round($mbps * 1_000_000 / 8 * $seconds);
    }

    private function timezone(string $name): string
    {
        return in_array($name, DateTimeZone::listIdentifiers(), true) ? $name : 'UTC';
    }
}