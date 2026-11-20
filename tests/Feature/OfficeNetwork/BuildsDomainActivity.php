<?php

declare(strict_types=1);

namespace Tests\Feature\OfficeNetwork;

use App\Models\ActivityDataSource;
use App\Models\DomainActivityRecord;
use App\Models\MonitoredDevice;

trait BuildsDomainActivity
{
    /** @param array<string, mixed> $attributes */
    protected function makeSource(string $type = 'dns', array $attributes = []): ActivityDataSource
    {
        $keys = ['dns' => 'mikrotik_dns_log', 'dhcp' => 'mikrotik_dhcp', 'proxy' => 'proxy_log', 'firewall' => 'firewall_log'];

        return ActivityDataSource::create([
            'key' => $keys[$type] ?? $type.'_source',
            'name' => 'Source '.$type,
            'type' => $type,
            'is_enabled' => true,
            'retention_days' => 90,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function makeRecord(ActivityDataSource $source, array $attributes = []): DomainActivityRecord
    {
        return DomainActivityRecord::create([
            'activity_data_source_id' => $source->id,
            'domain' => 'www.google.com',
            'base_domain' => 'google.com',
            'observation_type' => 'dns_query',
            'client_ip' => '192.168.10.50',
            'hit_count' => 1,
            'observed_at' => now()->subHour(),
            ...$attributes,
        ]);
    }

    protected function makeRouter(string $name = 'Core router'): MonitoredDevice
    {
        $device = new MonitoredDevice(['name' => $name, 'management_ip' => '192.0.2.1']);
        $device->forceFill(['is_demo' => false])->save();

        return $device;
    }
}