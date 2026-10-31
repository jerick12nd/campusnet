<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\MonitoredDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceCredentialProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(): MonitoredDevice
    {
        $device = new MonitoredDevice([
            'name' => 'Core Router',
            'management_ip' => '192.0.2.10',
            'device_type' => 'router',
            'snmp_version' => 'v2c',
            'credentials' => ['community' => 'super-secret-community'],
        ]);
        $device->save();

        return $device->refresh();
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $device = $this->makeDevice();

        $raw = DB::table('monitored_devices')->where('id', $device->id)->value('credentials');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('super-secret-community', $raw);
        $this->assertSame('super-secret-community', $device->credentials['community']);
    }

    public function test_credentials_are_hidden_from_serialisation(): void
    {
        $device = $this->makeDevice();

        $this->assertArrayNotHasKey('credentials', $device->toArray());
        $this->assertStringNotContainsString('super-secret-community', $device->toJson());
        $this->assertTrue($device->hasCredentials());
    }

    public function test_a_new_device_is_never_assumed_online(): void
    {
        $device = $this->makeDevice();

        $this->assertSame('unknown', $device->last_status->value);
        $this->assertNull($device->last_successful_poll_at);
    }
}