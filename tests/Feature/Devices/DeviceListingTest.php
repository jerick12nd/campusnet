<?php

declare(strict_types=1);

namespace Tests\Feature\Devices;

use App\Enums\UserRole;
use App\Models\MonitoredDevice;
use App\Models\NetworkAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class DeviceListingTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewer = $this->userWithRole(UserRole::Viewer);
    }

    public function test_the_list_is_paginated(): void
    {
        MonitoredDevice::factory()->count(17)->create();

        $this->actingAs($this->viewer)->get('/devices')
            ->assertInertia(fn (Assert $page) => $page
                ->component('devices/index')
                ->has('devices.data', 15)
                ->where('devices.total', 17)
                ->where('devices.last_page', 2)
                ->where('filters.per_page', 15));

        $this->actingAs($this->viewer)->get('/devices?page=2')
            ->assertInertia(fn (Assert $page) => $page->has('devices.data', 2));

        $this->actingAs($this->viewer)->get('/devices?per_page=10')
            ->assertInertia(fn (Assert $page) => $page->has('devices.data', 10)->where('devices.last_page', 2));
    }

    public function test_the_page_size_must_be_one_of_the_offered_sizes(): void
    {
        $this->actingAs($this->viewer)->get('/devices?per_page=100000')->assertSessionHasErrors('per_page');
    }

    public function test_search_matches_name_address_vendor_model_and_location(): void
    {
        MonitoredDevice::factory()->create(['name' => 'Library Switch', 'management_ip' => '10.0.1.10', 'vendor' => 'Cisco', 'model' => 'C2960', 'location' => 'Library']);
        MonitoredDevice::factory()->create(['name' => 'Gym Router', 'management_ip' => '10.0.2.20', 'vendor' => 'MikroTik', 'model' => 'RB4011', 'location' => 'Gymnasium']);

        foreach (['Library', '10.0.2', 'mikrotik', 'C2960', 'Gymnasium'] as $term) {
            $this->actingAs($this->viewer)->get('/devices?search='.urlencode($term))
                ->assertInertia(fn (Assert $page) => $page->has('devices.data', 1));
        }

        $this->actingAs($this->viewer)->get('/devices?search=nothing-matches-this')
            ->assertInertia(fn (Assert $page) => $page->has('devices.data', 0)->where('devices.total', 0));
    }

    public function test_search_text_is_never_interpreted_as_sql(): void
    {
        MonitoredDevice::factory()->create(['name' => 'Plain Device']);

        // The term is only ever bound as a parameter, so an injection attempt is just text that matches nothing.
        $this->actingAs($this->viewer)->get('/devices?search='.urlencode("' OR 1=1 --"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('devices.data', 0));
    }

    public function test_the_list_can_be_filtered(): void
    {
        MonitoredDevice::factory()->online()->create(['device_type' => 'router', 'location' => 'ICT Office']);
        MonitoredDevice::factory()->unreachable()->create(['device_type' => 'switch', 'location' => 'Library']);
        MonitoredDevice::factory()->disabled()->create(['device_type' => 'switch', 'location' => 'Library']);

        $this->actingAs($this->viewer)->get('/devices?status=online')
            ->assertInertia(fn (Assert $page) => $page->has('devices.data', 1)->where('devices.data.0.last_status.value', 'online'));

        $this->actingAs($this->viewer)->get('/devices?status=unreachable')
            ->assertInertia(fn (Assert $page) => $page->has('devices.data', 1));

        $this->actingAs($this->viewer)->get('/devices?type=switch')
            ->assertInertia(fn (Assert $page) => $page->has('devices.data', 2));

        $this->actingAs($this->viewer)->get('/devices?location=Library')
            ->assertInertia(fn (Assert $page) => $page->has('devices.data', 2));

        $this->actingAs($this->viewer)->get('/devices?enabled=no')
            ->assertInertia(fn (Assert $page) => $page->has('devices.data', 1)->where('devices.data.0.is_enabled', false));

        $this->actingAs($this->viewer)->get('/devices?enabled=yes&type=switch')
            ->assertInertia(fn (Assert $page) => $page->has('devices.data', 1));
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        $this->actingAs($this->viewer)->get('/devices?status=exploded')->assertSessionHasErrors('status');
        $this->actingAs($this->viewer)->get('/devices?type=toaster')->assertSessionHasErrors('type');
        $this->actingAs($this->viewer)->get('/devices?enabled=maybe')->assertSessionHasErrors('enabled');
    }

    public function test_the_list_can_be_sorted_in_both_directions(): void
    {
        MonitoredDevice::factory()->create(['name' => 'Bravo']);
        MonitoredDevice::factory()->create(['name' => 'Alpha']);
        MonitoredDevice::factory()->create(['name' => 'Charlie']);

        $this->actingAs($this->viewer)->get('/devices')
            ->assertInertia(fn (Assert $page) => $page->where('devices.data.0.name', 'Alpha')->where('filters.direction', 'asc'));

        $this->actingAs($this->viewer)->get('/devices?sort=name&direction=desc')
            ->assertInertia(fn (Assert $page) => $page->where('devices.data.0.name', 'Charlie')->where('devices.data.2.name', 'Alpha'));
    }

    public function test_sorting_is_limited_to_the_whitelisted_columns(): void
    {
        foreach (['credentials', 'id; DROP TABLE monitored_devices', 'password', 'name desc, (select 1)'] as $column) {
            $this->actingAs($this->viewer)->get('/devices?sort='.urlencode($column))->assertSessionHasErrors('sort');
        }

        $this->actingAs($this->viewer)->get('/devices?direction=sideways')->assertSessionHasErrors('direction');
        $this->assertDatabaseCount('monitored_devices', 0);
    }

    public function test_archived_devices_are_not_listed(): void
    {
        MonitoredDevice::factory()->create();
        MonitoredDevice::factory()->create()->delete();

        $this->actingAs($this->viewer)->get('/devices')
            ->assertInertia(fn (Assert $page) => $page->where('devices.total', 1));
    }

    public function test_rows_carry_counts_and_never_credentials(): void
    {
        $device = MonitoredDevice::factory()->create();
        NetworkAlert::factory()->create(['monitored_device_id' => $device->id]);
        NetworkAlert::factory()->resolved()->create(['monitored_device_id' => $device->id]);

        $this->actingAs($this->viewer)->get('/devices')
            ->assertInertia(fn (Assert $page) => $page
                ->where('devices.data.0.open_alerts_count', 1)
                ->where('devices.data.0.interfaces_count', 0)
                ->missing('devices.data.0.credentials')
                ->where('can.manage', false));
    }

    public function test_demo_devices_are_marked_as_demo(): void
    {
        MonitoredDevice::factory()->demo()->create();

        $this->actingAs($this->viewer)->get('/devices')
            ->assertInertia(fn (Assert $page) => $page->where('devices.data.0.is_demo', true));
    }
}