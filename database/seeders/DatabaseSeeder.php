<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Safe to run on an existing database: it only inserts missing defaults.
 * No user accounts are seeded; create the first Super Administrator with
 *   php artisan campusnet:create-user --role=super_admin
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(MonitoringSettingSeeder::class);
    }
}