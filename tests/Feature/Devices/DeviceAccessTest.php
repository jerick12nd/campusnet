<?php

declare(strict_types=1);

namespace Tests\Feature\Devices;

use App\Enums\UserRole;
use App\Models\MonitoredDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\DevicePayload;
use Tests\TestCase;

class DeviceAccessTest extends TestCase
{
    use CreatesUsers;
    use DevicePayload;
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $device = MonitoredDevice::factory()->create();

        $this->get('/devices')->assertRedirect(route('login'));
        $this->get("/devices/{$device->id}")->assertRedirect(route('login'));
        $this->get('/devices/create')->assertRedirect(route('login'));
        $this->post('/devices', $this->devicePayload())->assertRedirect(route('login'));
        $this->post("/devices/{$device->id}/test-connection")->assertRedirect(route('login'));
    }

    public function test_a_viewer_can_look_but_not_change_anything(): void
    {
        $viewer = $this->userWithRole(UserRole::Viewer);
        $device = MonitoredDevice::factory()->create();

        $this->actingAs($viewer)->get('/devices')->assertOk();
        $this->actingAs($viewer)->get("/devices/{$device->id}")->assertOk();

        $this->actingAs($viewer)->get('/devices/create')->assertForbidden();
        $this->actingAs($viewer)->post('/devices', $this->devicePayload())->assertForbidden();
        $this->actingAs($viewer)->get("/devices/{$device->id}/edit")->assertForbidden();
        $this->actingAs($viewer)->put("/devices/{$device->id}", $this->devicePayload())->assertForbidden();
        $this->actingAs($viewer)->patch("/devices/{$device->id}/toggle")->assertForbidden();
        $this->actingAs($viewer)->delete("/devices/{$device->id}")->assertForbidden();
        $this->actingAs($viewer)->post("/devices/{$device->id}/test-connection")->assertForbidden();

        $this->assertDatabaseCount('monitored_devices', 1);
        $this->assertNull($device->fresh()->deleted_at);
        $this->assertTrue($device->fresh()->is_enabled);
    }

    public function test_a_network_admin_can_manage_devices(): void
    {
        $admin = $this->userWithRole(UserRole::NetworkAdmin);
        $device = MonitoredDevice::factory()->create();

        $this->actingAs($admin)->get('/devices/create')->assertOk();
        $this->actingAs($admin)->get("/devices/{$device->id}/edit")->assertOk();
        $this->actingAs($admin)->patch("/devices/{$device->id}/toggle")->assertRedirect();
    }

    public function test_a_deactivated_account_cannot_reach_the_devices(): void
    {
        $inactive = $this->userWithRole(UserRole::SuperAdmin, false);

        $this->actingAs($inactive)->get('/devices')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_device_id_in_the_url_must_be_numeric(): void
    {
        $admin = $this->userWithRole(UserRole::NetworkAdmin);

        $this->actingAs($admin)->get('/devices/not-a-number')->assertNotFound();
    }

    public function test_an_archived_device_is_not_found(): void
    {
        $admin = $this->userWithRole(UserRole::NetworkAdmin);
        $device = MonitoredDevice::factory()->create();
        $device->delete();

        $this->actingAs($admin)->get("/devices/{$device->id}")->assertNotFound();
    }
}