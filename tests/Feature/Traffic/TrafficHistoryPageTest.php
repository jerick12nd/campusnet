<?php

declare(strict_types=1);

namespace Tests\Feature\Traffic;

use App\Enums\InterfaceRole;
use App\Models\MonitoredDevice;
use App\Models\NetworkInterface;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrafficHistoryPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/traffic-history')->assertRedirect();
    }

    public function test_it_defaults_to_the_wan_interface_and_reports_data_quality(): void
    {
        [$device, $lan, $wan] = $this->topology();
        $now = CarbonImmutable::now('UTC');

        $this->sample($device, $lan, $now->subMinutes(10), 'ok', 5.0, 2.0);
        $this->sample($device, $wan, $now->subMinutes(10), 'ok', 20.0, 5.0);
        $this->sample($device, $wan, $now->subMinutes(5), 'ok', 10.0, 4.0);
        $this->sample($device, $wan, $now->subMinutes(2), 'failed', null, null);

        $this->actingAs($this->viewer())
            ->get('/traffic-history')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('traffic-history')
                ->where('traffic.selected.id', $wan->id)
                ->where('traffic.selected.capacity_basis', 'internet_capacity')
                ->where('traffic.quality.ok', 2)
                ->where('traffic.quality.failed', 1)
                ->where('traffic.summary.peak_in_mbps', fn ($value) => abs($value - 20.0) < 0.001));
    }

    public function test_a_chosen_interface_is_measured_against_its_link_speed(): void
    {
        [, $lan] = $this->topology();

        $this->actingAs($this->viewer())
            ->get('/traffic-history?interface='.$lan->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('traffic.selected.id', $lan->id)
                ->where('traffic.selected.capacity_basis', 'link_speed'));
    }

    public function test_an_unknown_interface_falls_back_to_the_default(): void
    {
        [, , $wan] = $this->topology();

        $this->actingAs($this->viewer())
            ->get('/traffic-history?interface=999999')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('traffic.selected.id', $wan->id));
    }

    public function test_a_custom_range_needs_both_dates_and_is_limited(): void
    {
        $user = $this->viewer();

        $this->actingAs($user)->get('/traffic-history?range=custom')->assertSessionHasErrors(['from', 'to']);

        $this->actingAs($user)
            ->get('/traffic-history?range=custom&from=2026-01-01&to=2026-06-01')
            ->assertSessionHasErrors('to');
    }

    private function viewer(): User
    {
        return User::factory()->create(['role' => 'viewer', 'is_active' => true]);
    }

    /**
     * @return array{0: MonitoredDevice, 1: NetworkInterface, 2: NetworkInterface}
     */
    private function topology(): array
    {
        $device = new MonitoredDevice(['name' => 'Core router', 'management_ip' => '192.0.2.1']);
        $device->save();

        $lan = NetworkInterface::create([
            'monitored_device_id' => $device->id,
            'if_index' => 1,
            'name' => 'bridge-LAN',
            'role' => InterfaceRole::Lan,
            'is_monitored' => true,
            'speed_mbps' => 1000,
        ]);

        $wan = NetworkInterface::create([
            'monitored_device_id' => $device->id,
            'if_index' => 2,
            'name' => 'ether1-WAN',
            'role' => InterfaceRole::Wan,
            'is_monitored' => true,
            'speed_mbps' => 100,
        ]);

        return [$device, $lan, $wan];
    }

    private function sample(MonitoredDevice $device, NetworkInterface $interface, CarbonImmutable $at, string $status, ?float $in, ?float $out): void
    {
        DB::table('traffic_samples')->insert([
            'monitored_device_id' => $device->id,
            'network_interface_id' => $interface->id,
            'sampled_at' => $at->format('Y-m-d H:i:s'),
            'in_mbps' => $in,
            'out_mbps' => $out,
            'elapsed_seconds' => $status === 'ok' ? 60 : null,
            'source' => 'mock',
            'status' => $status,
            'is_successful' => $status === 'ok',
            'created_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }
}