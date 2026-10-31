<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MonitoringSetting;
use Illuminate\Database\Seeder;

/**
 * Writes the default settings only where no value exists yet, so re-running
 * the seeder never overwrites values an administrator has changed.
 */
class MonitoringSettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach ((array) config('campusnet.defaults') as $key => $value) {
            MonitoringSetting::firstOrCreate(
                ['key' => $key],
                ['value' => is_bool($value) ? (string) (int) $value : (string) $value],
            );
        }
    }
}