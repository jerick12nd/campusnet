<?php

declare(strict_types=1);

namespace Tests\Feature\Devices;

use App\Enums\DeviceStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\MonitoredDevice;
use App\Monitoring\ConnectionTestResult;
use App\Monitoring\Drivers\SnmpMonitoringDriver;
use App\Monitoring\Snmp\SnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\Support\FakeSnmpDriver;
use Tests\TestCase;

class ConnectionTestTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private function admin(): \App\Models\User
    {
        return $this->userWithRole(UserRole::NetworkAdmin);
    }

    private function useSnmpDriver(ConnectionTestResult|\Throwable $outcome): void
    {
        config(['campusnet.driver' => 'snmp']);
        $this->app->bind(SnmpMonitoringDriver::class, fn () => new FakeSnmpDriver($outcome));
    }

    public function test_demo_mode_sends_nothing_and_leaves_the_device_status_alone(): void
    {
        config(['campusnet.driver' => 'mock']);
        $device = MonitoredDevice::factory()->create();

        $this->actingAs($this->admin())->post("/devices/{$device->id}/test-connection")
            ->assertRedirect()
            ->assertSessionHas('connection_test.simulated', true)
            ->assertSessionHas('connection_test.device_id', $device->id)
            ->assertSessionHas('connection_test', fn (array $result): bool => $result['status'] === null);

        $fresh = $device->fresh();
        $this->assertSame(DeviceStatus::Unknown, $fresh->last_status);
        $this->assertNull($fresh->last_polled_at);
        $this->assertNull($fresh->last_successful_poll_at);

        $log = AuditLog::query()->where('action', 'device.connection_tested')->firstOrFail();
        $this->assertTrue($log->metadata['simulated']);
        $this->assertSame('mock', $log->metadata['driver']);
    }

    public function test_a_successful_real_test_marks_the_device_online(): void
    {
        $this->useSnmpDriver(ConnectionTestResult::reached('The device answered.', 12, ['sys_name' => 'core-rtr']));
        $device = MonitoredDevice::factory()->unreachable()->create();

        $this->actingAs($this->admin())->post("/devices/{$device->id}/test-connection")
            ->assertSessionHas('connection_test.success', true)
            ->assertSessionHas('connection_test.latency_ms', 12);

        $fresh = $device->fresh();
        $this->assertSame(DeviceStatus::Online, $fresh->last_status);
        $this->assertNotNull($fresh->last_successful_poll_at);
        $this->assertNull($fresh->last_error);
    }

    public function test_a_failed_real_test_marks_the_device_unreachable_and_keeps_the_last_success_time(): void
    {
        $this->useSnmpDriver(ConnectionTestResult::unreachable('No response from the device (timeout).'));
        $device = MonitoredDevice::factory()->online()->create(['last_successful_poll_at' => now()->subHour()]);
        $before = $device->fresh()->last_successful_poll_at;

        $this->actingAs($this->admin())->post("/devices/{$device->id}/test-connection")
            ->assertSessionHas('connection_test.success', false);

        $fresh = $device->fresh();
        $this->assertSame(DeviceStatus::Unreachable, $fresh->last_status);
        $this->assertSame('No response from the device (timeout).', $fresh->last_error);
        $this->assertEquals($before, $fresh->last_successful_poll_at);
    }

    public function test_a_setup_problem_says_nothing_about_the_device(): void
    {
        $this->useSnmpDriver(ConnectionTestResult::notPerformed('The PHP SNMP extension is not enabled on this server.'));
        $device = MonitoredDevice::factory()->online()->create();

        $this->actingAs($this->admin())->post("/devices/{$device->id}/test-connection")
            ->assertSessionHas('connection_test.success', false)
            ->assertSessionHas('connection_test', fn (array $result): bool => $result['status'] === null);

        $this->assertSame(DeviceStatus::Online, $device->fresh()->last_status, 'A server setup problem must not mark a device down.');
    }

    public function test_an_unexpected_driver_failure_is_reported_safely(): void
    {
        $this->useSnmpDriver(new RuntimeException('boom: secret-detail-123'));
        $device = MonitoredDevice::factory()->online()->create();

        $response = $this->actingAs($this->admin())->post("/devices/{$device->id}/test-connection");

        $response->assertSessionHas('connection_test.success', false);
        $this->assertStringNotContainsString('secret-detail-123', json_encode(session('connection_test')));
        $this->assertSame(DeviceStatus::Online, $device->fresh()->last_status);
    }

    public function test_an_unavailable_driver_is_reported_without_falling_back_to_demo_data(): void
    {
        foreach (['mikrotik', 'carrier-pigeon'] as $driver) {
            config(['campusnet.driver' => $driver]);
            $device = MonitoredDevice::factory()->create();

            $this->actingAs($this->admin())->post("/devices/{$device->id}/test-connection")
                ->assertSessionHas('connection_test.simulated', false)
                ->assertSessionHas('connection_test.success', false);
        }
    }

    public function test_the_real_driver_without_the_snmp_extension_says_so(): void
    {
        if (SnmpClient::isAvailable()) {
            $this->markTestSkipped('The SNMP extension is loaded here; this check is for servers without it.');
        }

        config(['campusnet.driver' => 'snmp']);
        $device = MonitoredDevice::factory()->online()->create();

        $this->actingAs($this->admin())->post("/devices/{$device->id}/test-connection")
            ->assertSessionHas('connection_test.success', false);

        $this->assertStringContainsString('extension', session('connection_test.message'));
        $this->assertSame(DeviceStatus::Online, $device->fresh()->last_status);
    }

    public function test_the_test_only_uses_the_stored_address_never_one_from_the_request(): void
    {
        $this->useSnmpDriver(ConnectionTestResult::reached('The device answered.', 5));
        $device = MonitoredDevice::factory()->create(['management_ip' => '10.1.1.1']);

        $this->actingAs($this->admin())->post("/devices/{$device->id}/test-connection", [
            'management_ip' => '169.254.169.254',
            'snmp_port' => 80,
        ])->assertRedirect();

        $this->assertSame('10.1.1.1', $device->fresh()->management_ip);
        $this->assertSame(161, $device->fresh()->snmp_port);
    }

    public function test_the_test_is_rate_limited(): void
    {
        $this->useSnmpDriver(ConnectionTestResult::reached('The device answered.', 5));
        $device = MonitoredDevice::factory()->create();
        $admin = $this->admin();

        foreach (range(1, 10) as $attempt) {
            $this->actingAs($admin)->post("/devices/{$device->id}/test-connection")->assertRedirect();
        }

        $this->actingAs($admin)->post("/devices/{$device->id}/test-connection")->assertStatus(429);
    }

    public function test_the_result_never_contains_credentials(): void
    {
        $this->useSnmpDriver(ConnectionTestResult::unreachable('SNMP authentication failed.'));
        $device = MonitoredDevice::factory()->create();

        $this->actingAs($this->admin())->post("/devices/{$device->id}/test-connection");

        $encoded = json_encode(session('connection_test')).json_encode(AuditLog::query()->get()->toArray());
        $this->assertStringNotContainsString('factory-auth-secret', $encoded);
        $this->assertStringNotContainsString('factory-priv-secret', $encoded);
    }
}