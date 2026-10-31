<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Enums\DeviceStatus;
use App\Enums\SampleStatus;
use App\Models\MonitoredDevice;
use App\Models\NetworkInterface;
use App\Models\TrafficSample;
use App\Monitoring\DiscoveredInterface;
use App\Monitoring\InterfaceCounters;
use App\Monitoring\PollingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePollingDriver;
use Tests\TestCase;

class PollingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_poll_stores_a_baseline_and_marks_the_device_online(): void
    {
        $device = $this->device();
        $interface = $this->interface($device);
        $driver = new FakePollingDriver;
        $driver->counters = [new InterfaceCounters(1, 1_000_000, 500_000)];

        $outcome = $this->service()->pollDevice($driver, $device);

        $this->assertTrue($outcome->successful);
        $this->assertSame(SampleStatus::Baseline, TrafficSample::sole()->status);
        $this->assertSame(1_000_000, $interface->refresh()->last_in_octets);
        $this->assertSame(DeviceStatus::Online, $device->refresh()->last_status);
    }

    public function test_second_poll_computes_the_rate(): void
    {
        $this->freezeTime();
        $device = $this->device();
        $this->interface($device);
        $driver = new FakePollingDriver;

        $driver->counters = [new InterfaceCounters(1, 1_000_000, 500_000)];
        $this->service()->pollDevice($driver, $device);

        $this->travel(60)->seconds();

        $driver->counters = [new InterfaceCounters(1, 8_500_000, 4_250_000)];
        $this->service()->pollDevice($driver, $device);

        $sample = TrafficSample::query()->latest('id')->firstOrFail();

        $this->assertSame(SampleStatus::Ok, $sample->status);
        $this->assertSame(60, $sample->elapsed_seconds);
        $this->assertEqualsWithDelta(1.0, (float) $sample->in_mbps, 0.0001);
        $this->assertEqualsWithDelta(0.5, (float) $sample->out_mbps, 0.0001);
    }

    public function test_a_failed_poll_marks_the_device_unreachable_and_records_a_failed_sample(): void
    {
        $device = $this->device();
        $this->interface($device);
        $driver = new FakePollingDriver;
        $driver->failWith = 'SNMP timeout';

        $outcome = $this->service()->pollDevice($driver, $device);
        $device->refresh();

        $this->assertFalse($outcome->successful);
        $this->assertSame(DeviceStatus::Unreachable, $device->last_status);
        $this->assertSame('SNMP timeout', $device->last_error);
        $this->assertNull($device->last_successful_poll_at);

        $sample = TrafficSample::sole();
        $this->assertSame(SampleStatus::Failed, $sample->status);
        $this->assertFalse($sample->is_successful);
        $this->assertNull($sample->in_mbps);
    }

    public function test_a_device_without_interfaces_discovers_them_and_monitors_only_ports_that_are_up(): void
    {
        $device = $this->device();
        $driver = new FakePollingDriver;
        $driver->discovered = [
            new DiscoveredInterface(1, 'ether1', null, 'up', 1000),
            new DiscoveredInterface(2, 'ether2', null, 'down', 1000),
        ];
        $driver->counters = [new InterfaceCounters(1, 100, 100)];

        $this->service()->pollDevice($driver, $device);

        $this->assertSame(2, NetworkInterface::count());
        $this->assertSame(1, NetworkInterface::where('is_monitored', true)->count());
        $this->assertSame(1, TrafficSample::count());
    }

    private function service(): PollingService
    {
        return $this->app->make(PollingService::class);
    }

    private function device(): MonitoredDevice
    {
        $device = new MonitoredDevice(['name' => 'Test switch', 'management_ip' => '192.0.2.10']);
        $device->save();

        return $device;
    }

    private function interface(MonitoredDevice $device): NetworkInterface
    {
        return NetworkInterface::create([
            'monitored_device_id' => $device->id,
            'if_index' => 1,
            'name' => 'ether1',
            'is_monitored' => true,
        ]);
    }
}