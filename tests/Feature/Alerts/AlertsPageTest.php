<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Models\MonitoredDevice;
use App\Models\NetworkAlert;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AlertsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/alerts')->assertRedirect();
    }

    public function test_the_default_view_lists_open_alerts_most_severe_first(): void
    {
        $device = $this->device();

        $warning = $this->alert($device, ['title' => 'High utilization', 'severity' => AlertSeverity::Warning, 'dedup_key' => 'a']);
        $critical = $this->alert($device, ['title' => 'Device unreachable', 'severity' => AlertSeverity::Critical, 'dedup_key' => 'b']);
        $this->alert($device, ['title' => 'Old problem', 'status' => AlertStatus::Resolved, 'dedup_key' => 'c'], resolved: true);

        $this->actingAs($this->userWithRole('viewer'))
            ->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('alerts/index')
                ->where('filters.status', 'open')
                ->where('stats.open', 2)
                ->where('stats.active', 2)
                ->where('stats.critical', 1)
                ->where('can.manage', false)
                ->has('alerts.data', 2)
                ->where('alerts.data.0.id', $critical->id)
                ->where('alerts.data.1.id', $warning->id));
    }

    public function test_the_status_filter_can_show_resolved_alerts_or_everything(): void
    {
        $device = $this->device();

        $this->alert($device, ['dedup_key' => 'a']);
        $this->alert($device, ['title' => 'Old problem', 'status' => AlertStatus::Resolved, 'dedup_key' => 'b'], resolved: true);

        $user = $this->userWithRole('viewer');

        $this->actingAs($user)
            ->get('/alerts?status=resolved')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('alerts.data', 1)->where('alerts.data.0.title', 'Old problem')->where('alerts.data.0.auto_resolved', true));

        $this->actingAs($user)
            ->get('/alerts?status=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('alerts.data', 2));
    }

    public function test_filters_narrow_the_list_and_invalid_values_are_rejected(): void
    {
        $device = $this->device();

        $this->alert($device, ['title' => 'Warning one', 'severity' => AlertSeverity::Warning, 'dedup_key' => 'a']);
        $this->alert($device, ['title' => 'Critical one', 'severity' => AlertSeverity::Critical, 'dedup_key' => 'b']);

        $user = $this->userWithRole('viewer');

        $this->actingAs($user)
            ->get('/alerts?severity=critical')
            ->assertInertia(fn (Assert $page) => $page->has('alerts.data', 1)->where('alerts.data.0.title', 'Critical one'));

        $this->actingAs($user)
            ->get('/alerts?search=Warning')
            ->assertInertia(fn (Assert $page) => $page->has('alerts.data', 1)->where('alerts.data.0.title', 'Warning one'));

        $this->actingAs($user)->get('/alerts?status=banana')->assertSessionHasErrors('status');
        $this->actingAs($user)->get('/alerts?severity=banana')->assertSessionHasErrors('severity');
    }

    public function test_a_viewer_cannot_acknowledge_or_resolve(): void
    {
        $alert = $this->alert($this->device());
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)->patch("/alerts/{$alert->id}/acknowledge")->assertForbidden();
        $this->actingAs($viewer)->patch("/alerts/{$alert->id}/resolve", ['note' => 'x'])->assertForbidden();

        $this->assertSame(AlertStatus::Active, $alert->refresh()->status);
    }

    public function test_an_administrator_can_acknowledge_and_it_is_audited(): void
    {
        $alert = $this->alert($this->device());
        $admin = $this->userWithRole('network_admin');

        $this->actingAs($admin)
            ->patch("/alerts/{$alert->id}/acknowledge")
            ->assertRedirect()
            ->assertSessionHas('success');

        $alert->refresh();

        $this->assertSame(AlertStatus::Acknowledged, $alert->status);
        $this->assertSame($admin->id, $alert->acknowledged_by);
        $this->assertNotNull($alert->acknowledged_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.acknowledged']);
    }

    public function test_an_administrator_can_resolve_with_a_note_and_it_is_audited(): void
    {
        $alert = $this->alert($this->device());
        $admin = $this->userWithRole('network_admin');

        $this->actingAs($admin)
            ->patch("/alerts/{$alert->id}/resolve", ['note' => 'Replaced the uplink cable.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $alert->refresh();

        $this->assertSame(AlertStatus::Resolved, $alert->status);
        $this->assertSame($admin->id, $alert->resolved_by);
        $this->assertSame('Replaced the uplink cable.', $alert->resolution_note);
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.resolved']);
    }

    public function test_a_resolution_note_cannot_be_longer_than_255_characters(): void
    {
        $alert = $this->alert($this->device());

        $this->actingAs($this->userWithRole('network_admin'))
            ->patch("/alerts/{$alert->id}/resolve", ['note' => str_repeat('a', 256)])
            ->assertSessionHasErrors('note');

        $this->assertSame(AlertStatus::Active, $alert->refresh()->status);
    }

    public function test_an_alert_that_is_already_resolved_cannot_be_changed_again(): void
    {
        $alert = $this->alert($this->device(), ['status' => AlertStatus::Resolved], resolved: true);
        $admin = $this->userWithRole('network_admin');

        $this->actingAs($admin)->patch("/alerts/{$alert->id}/acknowledge")->assertRedirect()->assertSessionHas('error');
        $this->actingAs($admin)->patch("/alerts/{$alert->id}/resolve")->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'alert.resolved']);
    }

    public function test_demo_alerts_are_hidden_once_a_real_driver_is_active(): void
    {
        $alert = $this->alert($this->device(demo: true));

        config(['campusnet.driver' => 'snmp']);

        $admin = $this->userWithRole('network_admin');

        $this->actingAs($admin)
            ->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.open', 0)->has('alerts.data', 0));

        $this->actingAs($admin)->patch("/alerts/{$alert->id}/acknowledge")->assertNotFound();
    }

    public function test_system_alerts_without_a_device_are_always_listed(): void
    {
        NetworkAlert::create([
            'type' => AlertType::NoRecentData,
            'severity' => AlertSeverity::Warning,
            'status' => AlertStatus::Active,
            'title' => 'No recent measurements',
            'dedup_key' => 'collector:no-data',
            'triggered_at' => CarbonImmutable::now(),
            'last_seen_at' => CarbonImmutable::now(),
        ]);

        config(['campusnet.driver' => 'snmp']);

        $this->actingAs($this->userWithRole('viewer'))
            ->get('/alerts')
            ->assertInertia(fn (Assert $page) => $page->has('alerts.data', 1)->where('alerts.data.0.device', null));
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function device(bool $demo = true): MonitoredDevice
    {
        $device = new MonitoredDevice(['name' => 'Core router', 'management_ip' => '192.0.2.1']);
        $device->forceFill(['is_demo' => $demo])->save();

        return $device;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function alert(MonitoredDevice $device, array $attributes = [], bool $resolved = false): NetworkAlert
    {
        $alert = new NetworkAlert(array_merge([
            'type' => AlertType::DeviceUnreachable,
            'severity' => AlertSeverity::Critical,
            'status' => AlertStatus::Active,
            'title' => 'Device unreachable: Core router',
            'monitored_device_id' => $device->id,
            'dedup_key' => 'device:'.$device->id.':unreachable',
            'triggered_at' => CarbonImmutable::now()->subMinutes(30),
            'last_seen_at' => CarbonImmutable::now(),
        ], $attributes));

        if ($resolved) {
            $alert->forceFill(['resolved_at' => CarbonImmutable::now()->subMinutes(5), 'resolved_by' => null]);
        }

        $alert->save();

        return $alert;
    }
}