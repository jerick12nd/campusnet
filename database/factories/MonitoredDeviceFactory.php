<?php

namespace Database\Factories;

use App\Models\MonitoredDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoredDevice>
 */
class MonitoredDeviceFactory extends Factory
{
    protected $model = MonitoredDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Device '.fake()->unique()->numerify('######'),
            'management_ip' => fake()->unique()->localIpv4(),
            'vendor' => 'MikroTik',
            'model' => 'RB4011',
            'device_type' => 'router',
            'location' => 'ICT Office',
            'description' => null,
            'is_enabled' => true,
            'snmp_version' => 'v3',
            'snmp_port' => 161,
            'polling_interval_seconds' => 60,
            'credentials' => [
                'username' => 'monitor',
                'security_level' => 'authPriv',
                'auth_protocol' => 'SHA',
                'auth_password' => 'factory-auth-secret',
                'priv_protocol' => 'AES',
                'priv_password' => 'factory-priv-secret',
            ],
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => [
            'last_status' => 'online',
            'last_polled_at' => now(),
            'last_successful_poll_at' => now(),
        ]);
    }

    public function unreachable(): static
    {
        return $this->state(fn () => [
            'last_status' => 'unreachable',
            'last_polled_at' => now(),
            'last_error' => 'No response from the device (timeout).',
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_enabled' => false]);
    }

    public function demo(): static
    {
        return $this->state(fn () => ['is_demo' => true]);
    }

    public function withoutCredentials(): static
    {
        return $this->state(fn () => ['credentials' => null]);
    }
}