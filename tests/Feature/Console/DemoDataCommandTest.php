<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MonitoredDevice;
use App\Models\NetworkInterface;
use App\Models\TrafficSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_create_demo_data_for_a_real_driver(): void
    {
        config(['campusnet.driver' => 'snmp']);

        $this->artisan('campusnet:demo-data', ['--days' => 1])->assertFailed();

        $this->assertDatabaseCount('monitored_devices', 0);
        $this->assertDatabaseCount('traffic_samples', 0);
    }

    public function test_it_creates_clearly_simulated_data(): void
    {
        config(['campusnet.driver' => 'mock']);

        $this->artisan('campusnet:demo-data', ['--days' => 1])->assertSuccessful();

        $this->assertSame(5, MonitoredDevice::count());
        $this->assertSame(5, MonitoredDevice::where('is_demo', true)->count());
        $this->assertSame(0, MonitoredDevice::where('is_demo', false)->count());
        $this->assertSame(0, MonitoredDevice::where('management_ip', 'not like', '192.0.2.%')->count(), 'Only RFC 5737 documentation addresses are used.');
        $this->assertSame(1, MonitoredDevice::where('last_status', 'unreachable')->count());

        $this->assertGreaterThan(0, TrafficSample::count());
        $this->assertSame(0, TrafficSample::where('source', '!=', 'mock')->count());
        $this->assertSame(1, NetworkInterface::where('role', 'wan')->count());

        // Utilization is stored only for the WAN link.
        $this->assertSame(0, TrafficSample::whereNotNull('utilization_percent')
            ->whereIn('network_interface_id', NetworkInterface::where('role', '!=', 'wan')->pluck('id'))->count());

        $this->assertDatabaseHas('audit_logs', ['action' => 'demo.generated']);
    }

    public function test_the_traffic_is_repeatable_and_stays_within_sensible_bounds(): void
    {
        config(['campusnet.driver' => 'mock']);

        $this->artisan('campusnet:demo-data', ['--days' => 1])->assertSuccessful();

        $max = (float) TrafficSample::max('in_mbps');
        $this->assertLessThanOrEqual(120.0, $max, 'Capped at 120% of the 100 Mbps default capacity.');
        $this->assertGreaterThanOrEqual(0.0, (float) TrafficSample::min('in_mbps'));
    }

    public function test_it_does_not_create_demo_data_twice(): void
    {
        config(['campusnet.driver' => 'mock']);

        $this->artisan('campusnet:demo-data', ['--days' => 1])->assertSuccessful();
        $count = TrafficSample::count();

        $this->artisan('campusnet:demo-data', ['--days' => 1])->assertFailed();

        $this->assertSame($count, TrafficSample::count());
        $this->assertSame(5, MonitoredDevice::count());
    }

    public function test_days_must_be_in_range(): void
    {
        config(['campusnet.driver' => 'mock']);

        $this->artisan('campusnet:demo-data', ['--days' => 0])->assertFailed();
        $this->artisan('campusnet:demo-data', ['--days' => 31])->assertFailed();

        $this->assertDatabaseCount('monitored_devices', 0);
    }

    public function test_purge_removes_only_demo_data(): void
    {
        config(['campusnet.driver' => 'mock']);
        $real = MonitoredDevice::factory()->create(['name' => 'Real device', 'management_ip' => '10.9.9.9']);
        $realInterface = NetworkInterface::factory()->create(['monitored_device_id' => $real->id]);
        TrafficSample::factory()->create(['network_interface_id' => $realInterface->id]);

        $this->artisan('campusnet:demo-data', ['--days' => 1])->assertSuccessful();
        $this->artisan('campusnet:demo-data', ['--purge' => true, '--force' => true])->assertSuccessful();

        $this->assertSame(1, MonitoredDevice::withTrashed()->count());
        $this->assertSame($real->id, MonitoredDevice::query()->firstOrFail()->id);
        $this->assertSame(1, NetworkInterface::count());
        $this->assertSame(1, TrafficSample::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'demo.purged']);
    }

    public function test_purge_works_even_when_a_real_driver_is_active(): void
    {
        config(['campusnet.driver' => 'mock']);
        $this->artisan('campusnet:demo-data', ['--days' => 1])->assertSuccessful();

        config(['campusnet.driver' => 'snmp']);
        $this->artisan('campusnet:demo-data', ['--purge' => true, '--force' => true])->assertSuccessful();

        $this->assertDatabaseCount('monitored_devices', 0);
        $this->assertDatabaseCount('traffic_samples', 0);
    }

    public function test_purge_with_nothing_to_remove_is_a_no_op(): void
    {
        $this->artisan('campusnet:demo-data', ['--purge' => true, '--force' => true])
            ->expectsOutputToContain('no demo data')
            ->assertSuccessful();
    }
}