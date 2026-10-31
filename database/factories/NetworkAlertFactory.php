<?php

namespace Database\Factories;

use App\Models\MonitoredDevice;
use App\Models\NetworkAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkAlert>
 */
class NetworkAlertFactory extends Factory
{
    protected $model = NetworkAlert::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'device_unreachable',
            'severity' => 'warning',
            'status' => 'active',
            'title' => 'Device unreachable',
            'description' => null,
            'monitored_device_id' => MonitoredDevice::factory(),
            'dedup_key' => fn () => 'test:'.fake()->unique()->numberBetween(1, 1000000),
            'triggered_at' => now(),
        ];
    }

    public function critical(): static
    {
        return $this->state(fn () => ['severity' => 'critical']);
    }

    public function acknowledged(): static
    {
        return $this->state(fn () => ['status' => 'acknowledged']);
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['status' => 'resolved', 'resolved_at' => now()]);
    }
}