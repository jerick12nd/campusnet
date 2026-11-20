<?php

declare(strict_types=1);

namespace Tests\Feature\OfficeNetwork;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ActivitySourceTest extends TestCase
{
    use BuildsDomainActivity;
    use BuildsOfficeNetwork;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);
    }

    public function test_only_a_super_administrator_can_open_or_change_sources(): void
    {
        $source = $this->makeSource('dns', ['is_enabled' => false]);

        foreach (['network_admin', 'viewer'] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)->get('/activity-sources')->assertForbidden();
            $this->actingAs($user)->patch("/activity-sources/{$source->id}", ['is_enabled' => false, 'retention_days' => 30])->assertForbidden();
        }

        $this->assertSame(90, $source->refresh()->retention_days);
    }

    public function test_the_page_lists_the_sources_and_routers(): void
    {
        $this->makeSource('dns', ['name' => 'MikroTik DNS query log']);
        $this->makeRouter('Core router');

        $this->actingAs($this->userWithRole('super_admin'))
            ->get('/activity-sources')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('activity-sources/index')
                ->where('sources.0.name', 'MikroTik DNS query log')
                ->where('sources.0.is_enabled', true)
                ->where('devices.0.name', 'Core router'));
    }

    public function test_a_source_cannot_be_enabled_without_a_router(): void
    {
        $source = $this->makeSource('dns', ['is_enabled' => false]);

        $this->actingAs($this->userWithRole('super_admin'))
            ->patch("/activity-sources/{$source->id}", ['is_enabled' => true, 'retention_days' => 30])
            ->assertSessionHasErrors('monitored_device_id');

        $this->assertFalse($source->refresh()->is_enabled);
    }

    public function test_a_source_can_be_enabled_with_a_router_and_it_is_audited(): void
    {
        $source = $this->makeSource('dns', ['is_enabled' => false]);
        $router = $this->makeRouter();

        $this->actingAs($this->userWithRole('super_admin'))
            ->patch("/activity-sources/{$source->id}", ['is_enabled' => true, 'retention_days' => 30, 'monitored_device_id' => $router->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $source->refresh();

        $this->assertTrue($source->is_enabled);
        $this->assertSame(30, $source->retention_days);
        $this->assertSame($router->id, $source->monitored_device_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'activity_source.updated']);
    }

    public function test_a_source_can_be_disabled_without_a_router(): void
    {
        $source = $this->makeSource('dns');

        $this->actingAs($this->userWithRole('super_admin'))
            ->patch("/activity-sources/{$source->id}", ['is_enabled' => false, 'retention_days' => 90])
            ->assertSessionHasNoErrors();

        $this->assertFalse($source->refresh()->is_enabled);
    }

    public function test_retention_and_router_are_validated(): void
    {
        $source = $this->makeSource('dns', ['is_enabled' => false]);
        $admin = $this->userWithRole('super_admin');

        foreach ([0, 3651, 'abc'] as $days) {
            $this->actingAs($admin)
                ->patch("/activity-sources/{$source->id}", ['is_enabled' => false, 'retention_days' => $days])
                ->assertSessionHasErrors('retention_days');
        }

        $this->actingAs($admin)
            ->patch("/activity-sources/{$source->id}", ['is_enabled' => false, 'retention_days' => 30, 'monitored_device_id' => 999999])
            ->assertSessionHasErrors('monitored_device_id');

        $this->assertSame(90, $source->refresh()->retention_days);
    }

    public function test_saving_the_same_values_changes_nothing_and_is_not_audited(): void
    {
        $source = $this->makeSource('dns', ['is_enabled' => false]);

        $this->actingAs($this->userWithRole('super_admin'))
            ->patch("/activity-sources/{$source->id}", ['is_enabled' => false, 'retention_days' => 90])
            ->assertRedirect();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'activity_source.updated']);
    }

    public function test_the_source_id_in_the_url_must_be_numeric(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->patch('/activity-sources/abc', ['is_enabled' => false, 'retention_days' => 30])
            ->assertNotFound();
    }
}