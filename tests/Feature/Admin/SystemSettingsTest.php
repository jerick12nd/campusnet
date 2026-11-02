<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected(): void
    {
        $this->get('/system-settings')->assertRedirect();
    }

    public function test_a_viewer_cannot_open_the_settings(): void
    {
        $this->actingAs($this->userWithRole('viewer'))->get('/system-settings')->assertForbidden();
    }

    public function test_a_network_administrator_can_read_but_not_change_the_settings(): void
    {
        $admin = $this->userWithRole('network_admin');

        $this->actingAs($admin)
            ->get('/system-settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('system-settings/edit')
                ->where('can.manage', false)
                ->where('settings.warning_threshold_percent', 80)
                ->has('timezones'));

        $this->actingAs($admin)->patch('/system-settings', $this->payload(['warning_threshold_percent' => 70]))->assertForbidden();

        $this->assertSame(80, app(SettingsService::class)->get('warning_threshold_percent'));
    }

    public function test_a_super_administrator_sees_the_form_with_the_current_values_and_the_fixed_ones(): void
    {
        config(['campusnet.retention.audit_logs_days' => 365, 'campusnet.driver' => 'mock']);

        $this->actingAs($this->userWithRole('super_admin'))
            ->get('/system-settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.manage', true)
                ->where('settings.timezone', 'Asia/Manila')
                ->where('defaults.critical_threshold_percent', 90)
                ->where('retention.audit_logs_days', 365)
                ->where('driver.name', 'mock')
                ->where('driver.demo', true));
    }

    public function test_saving_stores_only_what_changed_and_writes_one_audit_entry(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->patch('/system-settings', $this->payload(['warning_threshold_percent' => 70, 'campus_name' => 'DSSC Campus']))
            ->assertRedirect()
            ->assertSessionHas('success');

        $settings = app(SettingsService::class);

        $this->assertSame(70, $settings->get('warning_threshold_percent'));
        $this->assertSame('DSSC Campus', $settings->get('campus_name'));
        $this->assertDatabaseHas('monitoring_settings', ['key' => 'warning_threshold_percent', 'value' => '70']);
        $this->assertDatabaseMissing('monitoring_settings', ['key' => 'critical_threshold_percent']);

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'settings.updated')->count());

        $metadata = (string) DB::table('audit_logs')->where('action', 'settings.updated')->value('metadata');

        $this->assertStringContainsString('warning_threshold_percent', $metadata);
        $this->assertStringContainsString('DSSC Campus', $metadata);
    }

    public function test_saving_the_same_values_changes_nothing_and_is_not_audited(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->patch('/system-settings', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success', 'Nothing changed.');

        $this->assertSame(0, DB::table('monitoring_settings')->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'settings.updated']);
    }

    public function test_the_critical_threshold_must_be_higher_than_the_warning_threshold(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->patch('/system-settings', $this->payload(['warning_threshold_percent' => 90, 'critical_threshold_percent' => 90]))
            ->assertSessionHasErrors('critical_threshold_percent');

        $this->assertSame(80, app(SettingsService::class)->get('warning_threshold_percent'));
    }

    public function test_the_timeout_must_be_shorter_than_the_polling_interval(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->patch('/system-settings', $this->payload(['monitoring_interval_seconds' => 30, 'polling_timeout_seconds' => 30]))
            ->assertSessionHasErrors('polling_timeout_seconds');
    }

    public function test_invalid_values_are_rejected_and_nothing_is_saved(): void
    {
        $admin = $this->userWithRole('super_admin');

        foreach ([
            ['timezone', 'Mars/Olympus'],
            ['campus_name', ''],
            ['bandwidth_capacity_mbps', 0],
            ['warning_threshold_percent', 100],
            ['dashboard_refresh_seconds', 2],
            ['stale_data_minutes', 'soon'],
        ] as [$field, $value]) {
            $this->actingAs($admin)
                ->patch('/system-settings', $this->payload([$field => $value]))
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, DB::table('monitoring_settings')->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'settings.updated']);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /**
     * The defaults of config/campusnet.php, with some values replaced.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge((array) config('campusnet.defaults'), $overrides);
    }
}