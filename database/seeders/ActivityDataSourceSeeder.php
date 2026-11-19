<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ActivityDataSource;
use Illuminate\Database\Seeder;

class ActivityDataSourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            [
                'key' => 'mikrotik_dhcp',
                'name' => 'MikroTik DHCP leases',
                'type' => 'dhcp',
                'description' => 'Reads the router\'s DHCP lease table (read-only) to see which MAC address and IP '
                    .'are connected. Identifies devices only. It shows no websites. A device with a static IP '
                    .'will not appear here.',
                'retention_days' => 365,
            ],
            [
                'key' => 'mikrotik_dns_log',
                'name' => 'MikroTik DNS query log',
                'type' => 'dns',
                'description' => 'Reads DNS query entries from the router log (read-only). Requires the router to be '
                    .'the DNS server of the clients and a logging rule for the "dns" topic. Records domain names only. '
                    .'A DNS lookup does not prove a site was visited, and DNS-over-HTTPS bypasses it.',
                'retention_days' => 90,
            ],
        ];

        foreach ($sources as $source) {
            // firstOrCreate so re-seeding never flips a source the admin has already enabled.
            ActivityDataSource::firstOrCreate(['key' => $source['key']], $source + ['is_enabled' => false]);
        }
    }
}