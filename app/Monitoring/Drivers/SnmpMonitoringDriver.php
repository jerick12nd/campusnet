<?php

declare(strict_types=1);

namespace App\Monitoring\Drivers;

use App\Enums\DataSource;
use App\Enums\SnmpVersion;
use App\Models\MonitoredDevice;
use App\Monitoring\ConnectionTestResult;
use App\Monitoring\Contracts\PollingDriver;
use App\Monitoring\DiscoveredInterface;
use App\Monitoring\Exceptions\PollFailedException;
use App\Monitoring\Exceptions\SnmpException;
use App\Monitoring\InterfaceCounters;
use App\Monitoring\Snmp\SnmpClient;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/**
 * Real monitoring over SNMP (read-only GET and WALK requests).
 */
class SnmpMonitoringDriver implements PollingDriver
{
    // IF-MIB (32-bit counters and basic columns)
    private const OID_IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    private const OID_IF_SPEED = '1.3.6.1.2.1.2.2.1.5';

    private const OID_IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';

    private const OID_IF_IN_OCTETS = '1.3.6.1.2.1.2.2.1.10';

    private const OID_IF_OUT_OCTETS = '1.3.6.1.2.1.2.2.1.16';

    // IF-MIB extensions (64-bit counters, names, speed in Mbps)
    private const OID_IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';

    private const OID_IF_HC_IN_OCTETS = '1.3.6.1.2.1.31.1.1.1.6';

    private const OID_IF_HC_OUT_OCTETS = '1.3.6.1.2.1.31.1.1.1.10';

    private const OID_IF_HIGH_SPEED = '1.3.6.1.2.1.31.1.1.1.15';

    private const OID_IF_ALIAS = '1.3.6.1.2.1.31.1.1.1.18';

    public function __construct(private readonly SettingsService $settings) {}

    public function source(): DataSource
    {
        return DataSource::Snmp;
    }

    public function testConnection(MonitoredDevice $device): ConnectionTestResult
    {
        $problem = $this->preflightProblem($device);

        if ($problem !== null) {
            return ConnectionTestResult::notPerformed($problem);
        }

        $client = $this->buildClient($device, retries: 1);

        $startedAt = hrtime(true);

        try {
            $description = $client->get(SnmpClient::OID_SYS_DESCR);
        } catch (SnmpException $e) {
            return ConnectionTestResult::unreachable($e->getMessage());
        }

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $details = ['sys_descr' => Str::limit($description, 200)];

        // The device already answered, so these extras are best-effort.
        try {
            $details['sys_name'] = Str::limit($client->get(SnmpClient::OID_SYS_NAME), 120);
        } catch (SnmpException) {
        }

        try {
            $ticks = $client->get(SnmpClient::OID_SYS_UPTIME);

            if (ctype_digit($ticks)) {
                $seconds = intdiv((int) $ticks, 100);
                $details['uptime'] = intdiv($seconds, 86400).'d '.intdiv($seconds % 86400, 3600).'h';
            }
        } catch (SnmpException) {
        }

        if ($device->snmp_version === SnmpVersion::V2c) {
            $details['security_note'] = 'SNMPv2c sends the community string unencrypted. Use it only on a trusted management network, or move the device to SNMPv3.';
        }

        return ConnectionTestResult::reached("The device answered the SNMP request in {$latencyMs} ms.", $latencyMs, $details);
    }

    /**
     * @return list<InterfaceCounters>
     *
     * @throws PollFailedException
     */
    public function readCounters(MonitoredDevice $device): array
    {
        $client = $this->clientForPolling($device);

        try {
            // Prefer the 64-bit counters; old devices only have the 32-bit ones.
            try {
                $in = $client->walkByIndex(self::OID_IF_HC_IN_OCTETS);
                $out = $client->walkByIndex(self::OID_IF_HC_OUT_OCTETS);
                $bits = 64;

                if ($in === [] || $out === []) {
                    throw new SnmpException('No 64-bit counters.');
                }
            } catch (SnmpException) {
                $in = $client->walkByIndex(self::OID_IF_IN_OCTETS);
                $out = $client->walkByIndex(self::OID_IF_OUT_OCTETS);
                $bits = 32;
            }

            $status = $this->optionalWalk($client, self::OID_IF_OPER_STATUS);
            $highSpeed = $this->optionalWalk($client, self::OID_IF_HIGH_SPEED);
            $speed = $this->optionalWalk($client, self::OID_IF_SPEED);
        } catch (SnmpException $e) {
            throw new PollFailedException($e->getMessage());
        }

        if ($in === [] || $out === []) {
            throw new PollFailedException('The device did not report any interface counters.');
        }

        $counters = [];

        foreach ($in as $ifIndex => $inOctets) {
            if (! isset($out[$ifIndex]) || ! ctype_digit($inOctets) || ! ctype_digit($out[$ifIndex])) {
                continue;
            }

            $counters[] = new InterfaceCounters(
                ifIndex: $ifIndex,
                inOctets: (int) $inOctets,
                outOctets: (int) $out[$ifIndex],
                operStatus: $this->operStatus($status[$ifIndex] ?? null),
                speedMbps: $this->speedMbps($highSpeed[$ifIndex] ?? null, $speed[$ifIndex] ?? null),
                counterBits: $bits,
            );
        }

        return $counters;
    }

    /**
     * @return list<DiscoveredInterface>
     *
     * @throws PollFailedException
     */
    public function discoverInterfaces(MonitoredDevice $device): array
    {
        $client = $this->clientForPolling($device);

        try {
            $descr = $client->walkByIndex(self::OID_IF_DESCR);
            $name = $this->optionalWalk($client, self::OID_IF_NAME);
            $alias = $this->optionalWalk($client, self::OID_IF_ALIAS);
            $status = $this->optionalWalk($client, self::OID_IF_OPER_STATUS);
            $highSpeed = $this->optionalWalk($client, self::OID_IF_HIGH_SPEED);
            $speed = $this->optionalWalk($client, self::OID_IF_SPEED);
        } catch (SnmpException $e) {
            throw new PollFailedException($e->getMessage());
        }

        $interfaces = [];

        foreach ($descr as $ifIndex => $description) {
            $label = ($name[$ifIndex] ?? '') !== '' ? $name[$ifIndex] : $description;

            $interfaces[] = new DiscoveredInterface(
                ifIndex: $ifIndex,
                name: Str::limit($label, 120, ''),
                description: ($alias[$ifIndex] ?? '') !== '' ? Str::limit($alias[$ifIndex], 255, '') : null,
                operStatus: $this->operStatus($status[$ifIndex] ?? null),
                speedMbps: $this->speedMbps($highSpeed[$ifIndex] ?? null, $speed[$ifIndex] ?? null),
            );
        }

        return $interfaces;
    }

    /**
     * Why this device cannot be queried at all, or null when it can.
     */
    private function preflightProblem(MonitoredDevice $device): ?string
    {
        if (! SnmpClient::isAvailable()) {
            return 'The PHP SNMP extension is not enabled on this server, so real devices cannot be tested. '
                .'Enable extension=snmp in php.ini and restart the web server. Demo mode works without it.';
        }

        if ($device->snmp_version === SnmpVersion::V2c && ! config('campusnet.allow_snmp_v2c', false)) {
            return 'SNMPv2c is disabled on this server. Edit the device to use SNMPv3, or set CAMPUSNET_ALLOW_SNMP_V2C=true for an approved, trusted management network.';
        }

        if (empty($device->credentials)) {
            return 'No SNMP credentials are stored for this device. Edit the device and add them.';
        }

        return null;
    }

    private function buildClient(MonitoredDevice $device, int $retries): SnmpClient
    {
        return new SnmpClient(
            $device->management_ip,
            $device->snmp_port,
            $device->snmp_version,
            $device->credentials,
            (int) $this->settings->get('polling_timeout_seconds'),
            $retries,
        );
    }

    /**
     * @throws PollFailedException
     */
    private function clientForPolling(MonitoredDevice $device): SnmpClient
    {
        $problem = $this->preflightProblem($device);

        if ($problem !== null) {
            throw new PollFailedException($problem);
        }

        return $this->buildClient($device, retries: 1);
    }

    /**
     * A column that is nice to have: when the device does not support it, return nothing.
     *
     * @return array<int, string>
     */
    private function optionalWalk(SnmpClient $client, string $oid): array
    {
        try {
            return $client->walkByIndex($oid);
        } catch (SnmpException) {
            return [];
        }
    }

    private function operStatus(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $raw = strtolower($raw);

        return match (true) {
            $raw === '1', str_starts_with($raw, 'up') => 'up',
            $raw === '2', str_starts_with($raw, 'down') => 'down',
            default => $raw,
        };
    }

    /** ifHighSpeed is already in Mbps; ifSpeed is in bits per second and capped at about 4.29 Gbps. */
    private function speedMbps(?string $highSpeed, ?string $speedBps): ?int
    {
        if ($highSpeed !== null && ctype_digit($highSpeed) && (int) $highSpeed > 0) {
            return (int) $highSpeed;
        }

        if ($speedBps !== null && ctype_digit($speedBps) && (int) $speedBps > 0) {
            return intdiv((int) $speedBps, 1_000_000);
        }

        return null;
    }
}