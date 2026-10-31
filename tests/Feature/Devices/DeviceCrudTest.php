<?php

declare(strict_types=1);

namespace Tests\Feature\Devices;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\MonitoredDevice;
use App\Models\NetworkInterface;
use App\Models\TrafficSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\DevicePayload;
use Tests\TestCase;

class DeviceCrudTest extends TestCase
{
    use CreatesUsers;
    use DevicePayload;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(UserRole::NetworkAdmin);
    }

    public function test_a_device_can_be_added_with_snmpv3_credentials(): void
    {
        $response = $this->actingAs($this->admin)->post('/devices', $this->devicePayload());

        $device = MonitoredDevice::query()->firstOrFail();
        $response->assertRedirect("/devices/{$device->id}");

        $this->assertSame('Library Switch', $device->name);
        $this->assertSame('10.20.30.40', $device->management_ip);
        $this->assertSame('unknown', $device->last_status->value, 'A new device has not been tested yet.');
        $this->assertFalse($device->is_demo);
        $this->assertSame('campusnet-ro', $device->credentials['username']);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $this->actingAs($this->admin)->post('/devices', $this->devicePayload());

        $raw = (string) DB::table('monitored_devices')->value('credentials');

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('payload-auth-secret', $raw);
        $this->assertStringNotContainsString('payload-priv-secret', $raw);
        $this->assertStringNotContainsString('campusnet-ro', $raw);
    }

    public function test_creating_a_device_is_audited_without_secrets(): void
    {
        $this->actingAs($this->admin)->post('/devices', $this->devicePayload());

        $log = AuditLog::query()->where('action', 'device.created')->firstOrFail();

        $this->assertSame($this->admin->id, $log->user_id);

        $encoded = json_encode($log->toArray());
        $this->assertStringNotContainsString('payload-auth-secret', $encoded);
        $this->assertStringNotContainsString('payload-priv-secret', $encoded);
        $this->assertStringNotContainsString('campusnet-ro', $encoded);
    }

    public function test_credentials_never_reach_the_browser(): void
    {
        $device = MonitoredDevice::factory()->create();

        $this->actingAs($this->admin)->get("/devices/{$device->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('devices/show')
                ->where('device.has_credentials', true)
                ->missing('device.credentials'));

        $this->actingAs($this->admin)->get("/devices/{$device->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('devices/edit')
                ->where('device.has_credentials', true)
                ->missing('device.credentials'));

        // Belt and braces: the secret text appears nowhere in the rendered response.
        $this->actingAs($this->admin)->get("/devices/{$device->id}/edit")
            ->assertDontSee('factory-auth-secret')
            ->assertDontSee('factory-priv-secret');
    }

    public function test_secrets_are_not_flashed_back_when_validation_fails(): void
    {
        $response = $this->actingAs($this->admin)->from('/devices/create')->post('/devices', $this->devicePayload([
            'name' => '',
        ]));

        $response->assertRedirect('/devices/create');
        $response->assertSessionHasErrors('name');

        $old = session()->getOldInput();
        $this->assertArrayNotHasKey('credentials', $old, 'Add "credentials" to dontFlash() in bootstrap/app.php (Step 3).');
        $this->assertStringNotContainsString('payload-auth-secret', json_encode($old));
    }

    public function test_the_management_ip_must_be_a_safe_unicast_address(): void
    {
        foreach (['127.0.0.1', '0.0.0.0', '169.254.169.254', '224.0.0.1', '::1', 'fe80::1', 'not-an-ip', 'router.local'] as $ip) {
            $this->actingAs($this->admin)
                ->post('/devices', $this->devicePayload(['management_ip' => $ip]))
                ->assertSessionHasErrors('management_ip');
        }

        $this->assertDatabaseCount('monitored_devices', 0);

        foreach (['10.0.0.1', '172.16.5.5', '192.168.1.1', '203.0.113.7', '2001:db8::1'] as $index => $ip) {
            $this->actingAs($this->admin)
                ->post('/devices', $this->devicePayload(['name' => "Allowed {$index}", 'management_ip' => $ip]))
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('monitored_devices', 5);
    }

    public function test_name_and_address_must_be_unique_among_active_devices(): void
    {
        MonitoredDevice::factory()->create(['name' => 'Core Router', 'management_ip' => '10.1.1.1']);

        $this->actingAs($this->admin)
            ->post('/devices', $this->devicePayload(['name' => 'Core Router', 'management_ip' => '10.1.1.2']))
            ->assertSessionHasErrors('name');

        $this->actingAs($this->admin)
            ->post('/devices', $this->devicePayload(['name' => 'Another', 'management_ip' => '10.1.1.1']))
            ->assertSessionHasErrors('management_ip');
    }

    public function test_an_archived_devices_name_and_address_can_be_reused(): void
    {
        $old = MonitoredDevice::factory()->create(['name' => 'Core Router', 'management_ip' => '10.1.1.1']);
        $old->delete();

        $this->actingAs($this->admin)
            ->post('/devices', $this->devicePayload(['name' => 'Core Router', 'management_ip' => '10.1.1.1']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, MonitoredDevice::query()->count());
        $this->assertSame(2, MonitoredDevice::withTrashed()->count());
    }

    public function test_fields_are_validated(): void
    {
        $this->actingAs($this->admin)->post('/devices', $this->devicePayload([
            'device_type' => 'toaster',
            'snmp_port' => 70000,
            'polling_interval_seconds' => 5,
        ]))->assertSessionHasErrors(['device_type', 'snmp_port', 'polling_interval_seconds']);

        $this->actingAs($this->admin)->post('/devices', $this->devicePayload([
            'credentials' => ['auth_password' => 'short', 'priv_password' => 'short'],
        ]))->assertSessionHasErrors(['credentials.auth_password', 'credentials.priv_password']);

        $this->assertDatabaseCount('monitored_devices', 0);
    }

    public function test_snmpv3_without_authentication_is_not_accepted(): void
    {
        $this->actingAs($this->admin)->post('/devices', $this->devicePayload([
            'credentials' => ['security_level' => 'noAuthNoPriv'],
        ]))->assertSessionHasErrors('credentials.security_level');
    }

    public function test_auth_only_security_level_does_not_need_a_privacy_passphrase(): void
    {
        $payload = $this->devicePayload(['credentials' => ['security_level' => 'authNoPriv']]);
        unset($payload['credentials']['priv_protocol'], $payload['credentials']['priv_password']);

        $this->actingAs($this->admin)->post('/devices', $payload)->assertSessionHasNoErrors();

        $credentials = MonitoredDevice::query()->firstOrFail()->credentials;
        $this->assertArrayNotHasKey('priv_password', $credentials);
    }

    public function test_snmpv2c_is_refused_unless_the_server_allows_it(): void
    {
        $payload = $this->devicePayload([
            'snmp_version' => 'v2c',
            'credentials' => ['community' => 'Zx9-campus-ro'],
        ]);

        config(['campusnet.allow_snmp_v2c' => false]);
        $this->actingAs($this->admin)->post('/devices', $payload)->assertSessionHasErrors('snmp_version');
        $this->assertDatabaseCount('monitored_devices', 0);

        config(['campusnet.allow_snmp_v2c' => true]);
        $this->actingAs($this->admin)->post('/devices', $payload)->assertSessionHasNoErrors();
        $this->assertSame('v2c', MonitoredDevice::query()->firstOrFail()->snmp_version->value);
    }

    public function test_default_community_strings_are_rejected(): void
    {
        config(['campusnet.allow_snmp_v2c' => true]);

        foreach (['public', 'private', 'PUBLIC'] as $community) {
            $this->actingAs($this->admin)->post('/devices', $this->devicePayload([
                'snmp_version' => 'v2c',
                'credentials' => ['community' => $community],
            ]))->assertSessionHasErrors('credentials.community');
        }
    }

    public function test_only_offered_snmp_versions_are_listed_on_the_form(): void
    {
        config(['campusnet.allow_snmp_v2c' => false]);

        $this->actingAs($this->admin)->get('/devices/create')
            ->assertInertia(fn (Assert $page) => $page
                ->component('devices/create')
                ->has('options.versions', 1)
                ->where('options.versions.0.value', 'v3'));

        config(['campusnet.allow_snmp_v2c' => true]);

        $this->actingAs($this->admin)->get('/devices/create')
            ->assertInertia(fn (Assert $page) => $page->has('options.versions', 2));
    }

    public function test_editing_without_new_credentials_keeps_the_stored_ones(): void
    {
        $device = MonitoredDevice::factory()->create(['name' => 'Old Name']);
        $before = $device->credentials;

        $payload = $this->devicePayload(['name' => 'New Name', 'management_ip' => $device->management_ip]);
        $payload['credentials'] = null;

        $this->actingAs($this->admin)->put("/devices/{$device->id}", $payload)
            ->assertRedirect("/devices/{$device->id}")
            ->assertSessionHasNoErrors();

        $device->refresh();
        $this->assertSame('New Name', $device->name);
        $this->assertSame($before, $device->credentials);
    }

    public function test_editing_can_replace_credentials_and_says_so_in_the_audit_log(): void
    {
        $device = MonitoredDevice::factory()->create();

        $payload = $this->devicePayload([
            'name' => $device->name,
            'management_ip' => $device->management_ip,
            'credentials' => ['username' => 'rotated-user', 'auth_password' => 'rotated-auth-secret', 'priv_password' => 'rotated-priv-secret'],
        ]);

        $this->actingAs($this->admin)->put("/devices/{$device->id}", $payload)->assertSessionHasNoErrors();

        $this->assertSame('rotated-user', $device->fresh()->credentials['username']);

        $log = AuditLog::query()->where('action', 'device.updated')->latest('id')->firstOrFail();
        $this->assertTrue($log->metadata['snmp_auth_changed']);
        $this->assertStringNotContainsString('rotated-auth-secret', json_encode($log->toArray()));
        $this->assertStringNotContainsString('rotated-user', json_encode($log->toArray()));
    }

    public function test_changing_the_snmp_version_requires_new_credentials(): void
    {
        config(['campusnet.allow_snmp_v2c' => true]);
        $device = MonitoredDevice::factory()->create();

        $payload = $this->devicePayload(['name' => $device->name, 'management_ip' => $device->management_ip, 'snmp_version' => 'v2c']);
        $payload['credentials'] = null;

        $this->actingAs($this->admin)->put("/devices/{$device->id}", $payload)->assertSessionHasErrors('credentials');

        $this->assertSame('v3', $device->fresh()->snmp_version->value);
    }

    public function test_a_device_without_stored_credentials_must_be_given_some(): void
    {
        $device = MonitoredDevice::factory()->withoutCredentials()->create();

        $payload = $this->devicePayload(['name' => $device->name, 'management_ip' => $device->management_ip]);
        $payload['credentials'] = null;

        $this->actingAs($this->admin)->put("/devices/{$device->id}", $payload)->assertSessionHasErrors('credentials');
    }

    public function test_a_device_can_keep_its_own_name_and_address_when_edited(): void
    {
        $device = MonitoredDevice::factory()->create();

        $payload = $this->devicePayload(['name' => $device->name, 'management_ip' => $device->management_ip, 'location' => 'Moved']);
        $payload['credentials'] = null;

        $this->actingAs($this->admin)->put("/devices/{$device->id}", $payload)->assertSessionHasNoErrors();

        $this->assertSame('Moved', $device->fresh()->location);
    }

    public function test_a_device_can_be_disabled_and_enabled_again(): void
    {
        $device = MonitoredDevice::factory()->create();

        $this->actingAs($this->admin)->patch("/devices/{$device->id}/toggle")->assertRedirect();
        $this->assertFalse($device->fresh()->is_enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.disabled', 'subject_id' => $device->id]);

        $this->actingAs($this->admin)->patch("/devices/{$device->id}/toggle")->assertRedirect();
        $this->assertTrue($device->fresh()->is_enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.enabled', 'subject_id' => $device->id]);
    }

    public function test_archiving_keeps_the_interfaces_and_traffic_history(): void
    {
        $device = MonitoredDevice::factory()->create();
        $interface = NetworkInterface::factory()->wan()->create(['monitored_device_id' => $device->id]);
        TrafficSample::factory()->create(['network_interface_id' => $interface->id]);

        $this->actingAs($this->admin)->delete("/devices/{$device->id}")->assertRedirect('/devices');

        $this->assertSoftDeleted('monitored_devices', ['id' => $device->id]);
        $this->assertSame(1, NetworkInterface::query()->count());
        $this->assertSame(1, TrafficSample::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.archived', 'subject_id' => $device->id]);
    }

    public function test_the_detail_page_shows_the_devices_interfaces_and_alerts(): void
    {
        $device = MonitoredDevice::factory()->online()->create();
        NetworkInterface::factory()->wan()->create(['monitored_device_id' => $device->id, 'name' => 'ether1-WAN']);

        $this->actingAs($this->admin)->get("/devices/{$device->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('devices/show')
                ->where('device.id', $device->id)
                ->where('device.last_status.value', 'online')
                ->has('interfaces', 1)
                ->where('interfaces.0.name', 'ether1-WAN')
                ->where('can.manage', true));
    }
}