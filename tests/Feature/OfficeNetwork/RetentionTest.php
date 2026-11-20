<?php

declare(strict_types=1);

namespace Tests\Feature\OfficeNetwork;

use App\Models\DeviceConnectionSession;
use App\Models\DomainActivityRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use BuildsDomainActivity;
    use BuildsOfficeNetwork;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);
    }

    public function test_records_older_than_the_source_retention_are_deleted_and_newer_ones_kept(): void
    {
        $source = $this->makeSource('dns', ['retention_days' => 30]);
        $old = $this->makeRecord($source, ['observed_at' => now()->subDays(40)]);
        $recent = $this->makeRecord($source, ['domain' => 'recent.example.com', 'observed_at' => now()->subDays(5)]);

        $this->artisan('office-network:prune')->assertSuccessful();

        $this->assertDatabaseMissing('domain_activity_records', ['id' => $old->id]);
        $this->assertDatabaseHas('domain_activity_records', ['id' => $recent->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'office_network.retention_pruned']);
    }

    public function test_each_source_uses_its_own_retention(): void
    {
        $short = $this->makeSource('dns', ['retention_days' => 30]);
        $long = $this->makeSource('proxy', ['retention_days' => 90]);

        $shortRecord = $this->makeRecord($short, ['observed_at' => now()->subDays(40)]);
        $longRecord = $this->makeRecord($long, ['observed_at' => now()->subDays(40)]);

        $this->artisan('office-network:prune')->assertSuccessful();

        $this->assertDatabaseMissing('domain_activity_records', ['id' => $shortRecord->id]);
        $this->assertDatabaseHas('domain_activity_records', ['id' => $longRecord->id]);
    }

    public function test_retention_applies_even_when_the_source_is_switched_off(): void
    {
        $source = $this->makeSource('dns', ['retention_days' => 30, 'is_enabled' => false]);
        $old = $this->makeRecord($source, ['observed_at' => now()->subDays(40)]);

        $this->artisan('office-network:prune')->assertSuccessful();

        $this->assertDatabaseMissing('domain_activity_records', ['id' => $old->id]);
    }

    public function test_only_closed_connection_sessions_of_the_dhcp_source_are_pruned(): void
    {
        $dhcp = $this->makeSource('dhcp', ['retention_days' => 7]);

        $oldClosed = $this->connect(null, ['activity_data_source_id' => $dhcp->id, 'started_at' => now()->subDays(11), 'ended_at' => now()->subDays(10), 'last_seen_at' => now()->subDays(10)]);
        $recentClosed = $this->connect(null, ['activity_data_source_id' => $dhcp->id, 'started_at' => now()->subDays(3), 'ended_at' => now()->subDays(2), 'last_seen_at' => now()->subDays(2)]);
        $stillOpen = $this->connect(null, ['activity_data_source_id' => $dhcp->id, 'started_at' => now()->subDays(30), 'last_seen_at' => now()->subDays(30)]);
        $noSource = $this->connect(null, ['started_at' => now()->subDays(30), 'ended_at' => now()->subDays(29), 'last_seen_at' => now()->subDays(29)]);

        $this->artisan('office-network:prune')->assertSuccessful();

        $this->assertNull(DeviceConnectionSession::find($oldClosed->id));
        $this->assertNotNull(DeviceConnectionSession::find($recentClosed->id));
        $this->assertNotNull(DeviceConnectionSession::find($stillOpen->id));
        $this->assertNotNull(DeviceConnectionSession::find($noSource->id));
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $source = $this->makeSource('dns', ['retention_days' => 30]);
        $this->makeRecord($source, ['observed_at' => now()->subDays(40)]);

        $this->artisan('office-network:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, DomainActivityRecord::count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'office_network.retention_pruned']);
    }

    public function test_nothing_to_delete_leaves_no_audit_record(): void
    {
        $source = $this->makeSource('dns', ['retention_days' => 30]);
        $this->makeRecord($source, ['observed_at' => now()->subDays(1)]);

        $this->artisan('office-network:prune')->assertSuccessful();

        $this->assertSame(1, DomainActivityRecord::count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'office_network.retention_pruned']);
    }
}