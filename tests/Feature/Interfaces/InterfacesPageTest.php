<?php

declare(strict_types=1);

namespace Tests\Feature\Interfaces;

use App\Enums\InterfaceRole;
use App\Models\MonitoredDevice;
use App\Models\NetworkInterface;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InterfacesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/interfaces')->assertRedirect();
    }

    public function test_a_viewer_sees_each_interface_with_its_latest_valid_rate(): void
    {
        [$device, $lan, $wan] = $this->topology();
        $now = CarbonImmutable::now('UTC');

        // The older valid sample and the newer failed one must both be ignored.
        $this->sample($device, $wan, $now->subMinutes(10), 'ok', 99.0, 9.0);
        $this->sample($device, $wan, $now->subMinutes(2), 'ok', 12.5, 3.0);
        $this->sample($device, $wan, $now->subMinutes(1), 'failed', null, null);

        $this->actingAs($this->userWithRole('viewer'))
            ->get('/interfaces')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('interfaces/index')
                ->where('stats.total', 2)
                ->where('stats.monitored', 2)
                ->where('can.manage', false)
                ->has('interfaces.data', 2)
                ->where('interfaces.data.0.id', $lan->id)
                ->where('interfaces.data.0.latest', null)
                ->where('interfaces.data.1.id', $wan->id)
                ->where('interfaces.data.1.latest.in_mbps', fn ($value) => abs($value - 12.5) < 0.001));
    }

    public function test_filters_narrow_the_list(): void
    {
        $this->topology();

        $user = $this->userWithRole('viewer');

        $this->actingAs($user)
            ->get('/interfaces?role=wan')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('interfaces.data', 1)->where('interfaces.data.0.name', 'ether1-WAN'));

        $this->actingAs($user)
            ->get('/interfaces?search=bridge')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('interfaces.data', 1)->where('interfaces.data.0.name', 'bridge-LAN'));

        $this->actingAs($user)->get('/interfaces?role=not-a-role')->assertSessionHasErrors('role');
    }

    public function test_a_viewer_cannot_change_an_interface(): void
    {
        [, , $wan] = $this->topology();

        $this->actingAs($this->userWithRole('viewer'))
            ->patch("/interfaces/{$wan->id}", ['role' => 'lan'])
            ->assertForbidden();

        $this->assertSame(InterfaceRole::Wan, $wan->refresh()->role);
    }

    public function test_an_administrator_can_change_the_role_and_monitoring_and_it_is_audited(): void
    {
        [, $lan] = $this->topology();

        $this->actingAs($this->userWithRole('network_admin'))
            ->patch("/interfaces/{$lan->id}", ['role' => 'wan', 'is_monitored' => false])
            ->assertRedirect();

        $lan->refresh();

        $this->assertSame(InterfaceRole::Wan, $lan->role);
        $this->assertFalse($lan->is_monitored);
        $this->assertDatabaseHas('audit_logs', ['action' => 'interface.updated']);
    }

    public function test_an_invalid_role_is_rejected(): void
    {
        [, $lan] = $this->topology();

        $this->actingAs($this->userWithRole('network_admin'))
            ->patch("/interfaces/{$lan->id}", ['role' => 'banana'])
            ->assertSessionHasErrors('role');

        $this->assertSame(InterfaceRole::Lan, $lan->refresh()->role);
    }

    public function test_demo_devices_are_hidden_once_a_real_driver_is_active(): void
    {
        [, $lan] = $this->topology(demo: true);

        config(['campusnet.driver' => 'snmp']);

        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/interfaces')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.total', 0)->has('interfaces.data', 0));

        $this->actingAs($this->userWithRole('network_admin'))
            ->patch("/interfaces/{$lan->id}", ['role' => 'wan'])
            ->assertNotFound();
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /**
     * @return array{0: MonitoredDevice, 1: NetworkInterface, 2: NetworkInterface}
     */
    private function topology(bool $demo = false): array
    {
        $device = new MonitoredDevice(['name' => 'Core router', 'management_ip' => '192.0.2.1']);
        $device->forceFill(['is_demo' => $demo])->save();

        $lan = NetworkInterface::create([
            'monitored_device_id' => $device->id,
            'if_index' => 1,
            'name' => 'bridge-LAN',
            'role' => InterfaceRole::Lan,
            'is_monitored' => true,
            'oper_status' => 'up',
            'speed_mbps' => 1000,
        ]);

        $wan = NetworkInterface::create([
            'monitored_device_id' => $device->id,
            'if_index' => 2,
            'name' => 'ether1-WAN',
            'role' => InterfaceRole::Wan,
            'is_monitored' => true,
            'oper_status' => 'up',
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