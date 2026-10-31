<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MonitoredDevice;
use App\Models\NetworkInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'campusnet.retention.traffic_samples_days' => 30,
            'campusnet.retention.audit_logs_days' => 90,
        ]);
    }

    public function test_it_deletes_only_data_older_than_the_retention_window(): void
    {
        [$device, $interface] = $this->deviceWithInterface();

        $this->sample($device, $interface, now()->subDays(40));
        $this->sample($device, $interface, now()->subDays(5));
        $this->auditLog('test.old', now()->subDays(120));
        $this->auditLog('test.recent', now()->subDays(10));

        $this->artisan('campusnet:prune')->assertSuccessful();

        $this->assertSame(1, DB::table('traffic_samples')->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'test.old']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'test.recent']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'data.pruned']);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        [$device, $interface] = $this->deviceWithInterface();

        $this->sample($device, $interface, now()->subDays(40));
        $this->auditLog('test.old', now()->subDays(120));

        $this->artisan('campusnet:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, DB::table('traffic_samples')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'test.old']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'data.pruned']);
    }

    public function test_nothing_to_delete_leaves_no_audit_record(): void
    {
        $this->artisan('campusnet:prune')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'data.pruned']);
    }

    /**
     * @return array{0: MonitoredDevice, 1: NetworkInterface}
     */
    private function deviceWithInterface(): array
    {
        $device = new MonitoredDevice(['name' => 'Test switch', 'management_ip' => '192.0.2.10']);
        $device->save();

        $interface = NetworkInterface::create([
            'monitored_device_id' => $device->id,
            'if_index' => 1,
            'name' => 'ether1',
        ]);

        return [$device, $interface];
    }

    private function sample(MonitoredDevice $device, NetworkInterface $interface, \DateTimeInterface $at): void
    {
        DB::table('traffic_samples')->insert([
            'monitored_device_id' => $device->id,
            'network_interface_id' => $interface->id,
            'sampled_at' => $at->format('Y-m-d H:i:s'),
            'source' => 'mock',
            'status' => 'ok',
            'created_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }

    private function auditLog(string $action, \DateTimeInterface $at): void
    {
        DB::table('audit_logs')->insert([
            'action' => $action,
            'created_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }
}