<?php

declare(strict_types=1);

namespace App\Monitoring\Drivers;

use App\Enums\DataSource;
use App\Enums\SnmpVersion;
use App\Models\MonitoredDevice;
use App\Monitoring\ConnectionTestResult;
use App\Monitoring\Contracts\MonitoringDriver;
use App\Monitoring\Exceptions\SnmpException;
use App\Monitoring\Snmp\SnmpClient;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/**
 * Real monitoring over SNMP (read-only GET requests).
 */
class SnmpMonitoringDriver implements MonitoringDriver
{
    public function __construct(private readonly SettingsService $settings) {}

    public function source(): DataSource
    {
        return DataSource::Snmp;
    }

    public function testConnection(MonitoredDevice $device): ConnectionTestResult
    {
        if (! SnmpClient::isAvailable()) {
            return ConnectionTestResult::notPerformed(
                'The PHP SNMP extension is not enabled on this server, so real devices cannot be tested. '
                .'Enable extension=snmp in php.ini and restart the web server. Demo mode works without it.'
            );
        }

        if ($device->snmp_version === SnmpVersion::V2c && ! config('campusnet.allow_snmp_v2c', false)) {
            return ConnectionTestResult::notPerformed(
                'SNMPv2c is disabled on this server. Edit the device to use SNMPv3, or set CAMPUSNET_ALLOW_SNMP_V2C=true for an approved, trusted management network.'
            );
        }

        $credentials = $device->credentials;

        if (empty($credentials)) {
            return ConnectionTestResult::notPerformed(
                'No SNMP credentials are stored for this device. Edit the device and add them.'
            );
        }

        $client = new SnmpClient(
            $device->management_ip,
            $device->snmp_port,
            $device->snmp_version,
            $credentials,
            (int) $this->settings->get('polling_timeout_seconds'),
            1,
        );

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
}