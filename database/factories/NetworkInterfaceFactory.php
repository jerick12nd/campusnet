<?php

namespace Database\Factories;

use App\Models\MonitoredDevice;
use App\Models\NetworkInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkInterface>
 */
class NetworkInterfaceFactory extends Factory
{
    protected $model = NetworkInterface::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitored_device_id' => MonitoredDevice::factory(),
            'if_index' => fake()->unique()->numberBetween(1, 100000),
            'name' => 'ether'.fake()->numberBetween(1, 24),
            'description' => null,
            'role' => 'other',
            'is_monitored' => true,
            'oper_status' => 'up',
            'speed_mbps' => 1000,
        ];
    }

    public function wan(): static
    {
        return $this->state(fn () => ['role' => 'wan', 'name' => 'ether1-WAN']);
    }

    public function lan(): static
    {
        return $this->state(fn () => ['role' => 'lan']);
    }
}