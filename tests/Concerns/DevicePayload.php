<?php

declare(strict_types=1);

namespace Tests\Concerns;

trait DevicePayload
{
    /**
     * A valid SNMPv3 (authPriv) device submission, as the add/edit form sends it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function devicePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Library Switch',
            'management_ip' => '10.20.30.40',
            'vendor' => 'Cisco',
            'model' => 'Catalyst 2960',
            'device_type' => 'switch',
            'location' => 'Library',
            'description' => null,
            'is_enabled' => true,
            'snmp_version' => 'v3',
            'snmp_port' => 161,
            'polling_interval_seconds' => 60,
            'credentials' => [
                'username' => 'campusnet-ro',
                'security_level' => 'authPriv',
                'auth_protocol' => 'SHA',
                'auth_password' => 'payload-auth-secret',
                'priv_protocol' => 'AES',
                'priv_password' => 'payload-priv-secret',
            ],
        ], $overrides);
    }
}