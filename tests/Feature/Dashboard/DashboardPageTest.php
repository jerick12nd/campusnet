<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\UserRole;
use App\Models\MonitoredDevice;
use App\Models\NetworkInterface;
use App\Models\TrafficSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DashboardPageTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_every_role_can_open_the_dashboard(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->actingAs($this->userWithRole($role))->get('/dashboard')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('dashboard')->has('dashboard'));
        }
    }

    public function test_the_page_carries_real_numbers_not_placeholders(): void
    {
        $device = MonitoredDevice::factory()->online()->create();
        $wan = NetworkInterface::factory()->wan()->create(['monitored_device_id' => $device->id]);
        TrafficSample::factory()->rate(55.5, 12.25)->create(['network_interface_id' => $wan->id, 'sampled_at' => now()]);

        $this->actingAs($this->userWithRole(UserRole::Viewer))->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.mode.demo', true)
                ->where('dashboard.collector.status', 'ok')
                ->where('dashboard.wan.current.in_mbps', fn ($value) => abs($value - 55.5) < 0.001)
                ->where('dashboard.wan.current.out_mbps', fn ($value) => abs($value - 12.25) < 0.001)
                ->where('dashboard.wan.current.utilization_percent', fn ($value) => abs($value - 55.5) < 0.001)
                ->where('dashboard.devices.total', 1)
                ->where('dashboard.devices.online', 1)
                ->has('dashboard.history', 1)
                ->has('dashboard.top_interfaces', 1));
    }

    public function test_the_range_is_validated(): void
    {
        $viewer = $this->userWithRole(UserRole::Viewer);

        foreach (['1h', '24h', '7d', '30d'] as $range) {
            $this->actingAs($viewer)->get("/dashboard?range={$range}")
                ->assertInertia(fn (Assert $page) => $page->where('dashboard.range', $range));
        }

        $this->actingAs($viewer)->get('/dashboard?range=1000y')->assertSessionHasErrors('range');
        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('dashboard.range', '24h'));
    }

    public function test_it_reports_the_real_driver_when_one_is_selected(): void
    {
        config(['campusnet.driver' => 'snmp']);

        $this->actingAs($this->userWithRole(UserRole::Viewer))->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.mode.demo', false)
                ->where('dashboard.mode.driver', 'snmp')
                ->where('dashboard.collector.status', 'no_devices'));
    }
}