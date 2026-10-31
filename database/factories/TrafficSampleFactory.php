<?php

namespace Database\Factories;

use App\Models\NetworkInterface;
use App\Models\TrafficSample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficSample>
 */
class TrafficSampleFactory extends Factory
{
    protected $model = TrafficSample::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'network_interface_id' => NetworkInterface::factory(),
            // Keep the device in step with the interface the sample belongs to.
            'monitored_device_id' => fn (array $attributes) => NetworkInterface::find($attributes['network_interface_id'])->monitored_device_id,
            'sampled_at' => now(),
            'in_mbps' => 10,
            'out_mbps' => 4,
            'utilization_percent' => null,
            'elapsed_seconds' => 60,
            'source' => 'mock',
            'status' => 'ok',
            'is_successful' => true,
        ];
    }

    public function source(string $source): static
    {
        return $this->state(fn () => ['source' => $source]);
    }

    public function at(\DateTimeInterface|string $when): static
    {
        return $this->state(fn () => ['sampled_at' => $when]);
    }

    public function rate(float $in, float $out): static
    {
        return $this->state(fn () => ['in_mbps' => $in, 'out_mbps' => $out]);
    }
}