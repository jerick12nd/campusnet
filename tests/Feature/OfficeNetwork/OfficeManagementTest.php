<?php

declare(strict_types=1);

namespace Tests\Feature\OfficeNetwork;

use App\Models\Office;
use App\Models\RegisteredDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficeManagementTest extends TestCase
{
    use BuildsOfficeNetwork;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);
    }

    public function test_guests_cannot_manage_offices(): void
    {
        $office = $this->makeOffice();

        $this->post('/offices', ['name' => 'New'])->assertRedirect();
        $this->patch("/offices/{$office->id}", ['name' => 'Renamed'])->assertRedirect();
        $this->delete("/offices/{$office->id}")->assertRedirect();

        $this->assertSame(1, Office::count());
        $this->assertSame('CIDS Office', $office->refresh()->name);
    }

    public function test_only_a_super_administrator_can_manage_offices(): void
    {
        $office = $this->makeOffice();

        foreach (['viewer', 'network_admin'] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)->post('/offices', ['name' => 'Sneaky'])->assertForbidden();
            $this->actingAs($user)->patch("/offices/{$office->id}", ['name' => 'Renamed'])->assertForbidden();
            $this->actingAs($user)->delete("/offices/{$office->id}")->assertForbidden();
        }

        $this->assertSame(1, Office::count());
        $this->assertSame('CIDS Office', $office->refresh()->name);
        $this->assertNull($office->deleted_at);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'office.created']);
    }

    public function test_a_super_administrator_can_add_an_office_and_it_is_audited(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->post('/offices', [
                'name' => 'CIDS Office',
                'code' => 'CIDS',
                'department' => 'Computing',
                'subnet_cidr' => '192.168.10.0/24',
                'description' => 'ICT department office',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('offices', ['name' => 'CIDS Office', 'code' => 'CIDS', 'subnet_cidr' => '192.168.10.0/24', 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'office.created']);
    }

    public function test_fields_are_validated(): void
    {
        $this->makeOffice('CIDS Office', ['code' => 'CIDS']);
        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)->post('/offices', ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/offices', ['name' => 'CIDS Office'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/offices', ['name' => 'Other', 'code' => 'CIDS'])->assertSessionHasErrors('code');
        $this->actingAs($admin)->post('/offices', ['name' => 'Other', 'description' => str_repeat('x', 1001)])->assertSessionHasErrors('description');
        $this->actingAs($admin)->post('/offices', ['name' => str_repeat('x', 256)])->assertSessionHasErrors('name');

        $this->assertSame(1, Office::count());
    }

    public function test_a_subnet_must_be_valid_cidr_notation(): void
    {
        $admin = $this->userWithRole('super_admin');

        foreach (['not-a-subnet', '192.168.10.0', '192.168.10.0/33', '192.168.10.999/24', '192.168.10.0/abc', 'fd00::/129'] as $bad) {
            $this->actingAs($admin)
                ->post('/offices', ['name' => 'Test', 'subnet_cidr' => $bad])
                ->assertSessionHasErrors('subnet_cidr');
        }

        $this->assertSame(0, Office::count());
    }

    public function test_valid_ipv4_and_ipv6_subnets_are_accepted(): void
    {
        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)->post('/offices', ['name' => 'V4', 'subnet_cidr' => '10.20.0.0/16'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/offices', ['name' => 'V6', 'subnet_cidr' => 'fd00:10::/64'])->assertSessionHasNoErrors();

        $this->assertSame(2, Office::count());
    }

    public function test_an_archived_offices_name_stays_reserved(): void
    {
        $this->makeOffice('Old name')->delete();

        $this->actingAs($this->userWithRole('super_admin'))
            ->post('/offices', ['name' => 'Old name'])
            ->assertSessionHasErrors('name');
    }

    public function test_an_office_can_be_edited_and_it_is_audited(): void
    {
        $office = $this->makeOffice('CIDS Office', ['code' => 'CIDS']);

        $this->actingAs($this->userWithRole('super_admin'))
            ->patch("/offices/{$office->id}", ['name' => 'CIDS Office', 'code' => 'CIDS', 'department' => 'Computing'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('Computing', $office->refresh()->department);
        $this->assertDatabaseHas('audit_logs', ['action' => 'office.updated']);
    }

    public function test_saving_the_same_values_changes_nothing_and_is_not_audited(): void
    {
        $office = $this->makeOffice('CIDS Office', ['code' => 'CIDS']);

        // Keeping its own name and code must not trip the unique rules either.
        $this->actingAs($this->userWithRole('super_admin'))
            ->patch("/offices/{$office->id}", ['name' => 'CIDS Office', 'code' => 'CIDS'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'office.updated']);
    }

    public function test_an_office_cannot_take_the_name_of_another(): void
    {
        $this->makeOffice('Registrar');
        $office = $this->makeOffice('CIDS Office');

        $this->actingAs($this->userWithRole('super_admin'))
            ->patch("/offices/{$office->id}", ['name' => 'Registrar'])
            ->assertSessionHasErrors('name');

        $this->assertSame('CIDS Office', $office->refresh()->name);
    }

    public function test_an_office_with_active_members_cannot_be_archived(): void
    {
        $office = $this->makeOffice();
        $this->makeMember($office);

        $this->actingAs($this->userWithRole('super_admin'))
            ->delete("/offices/{$office->id}")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('offices', ['id' => $office->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'office.archived']);
    }

    public function test_an_office_with_an_active_shared_device_cannot_be_archived(): void
    {
        $office = $this->makeOffice();
        RegisteredDevice::create([
            'office_id' => $office->id,
            'name' => 'Office printer',
            'device_type' => 'printer',
            'mac_address' => 'AA:BB:CC:00:00:99',
        ]);

        $this->actingAs($this->userWithRole('super_admin'))
            ->delete("/offices/{$office->id}")
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('offices', ['id' => $office->id]);
    }

    public function test_an_office_without_active_people_is_archived_and_its_history_is_kept(): void
    {
        $office = $this->makeOffice();
        $member = $this->makeMember($office, 'Former Member', active: false);
        $session = $this->connect(null, ['office_id' => $office->id, 'ended_at' => now()->subDay()]);

        $this->actingAs($this->userWithRole('super_admin'))
            ->delete("/offices/{$office->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSoftDeleted('offices', ['id' => $office->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'office.archived']);

        // Archiving never deletes members or past connections.
        $this->assertDatabaseHas('members', ['id' => $member->id]);
        $this->assertDatabaseHas('device_connection_sessions', ['id' => $session->id, 'office_id' => $office->id]);
    }

    public function test_the_office_id_in_the_url_must_be_numeric(): void
    {
        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)->patch('/offices/abc', ['name' => 'X'])->assertNotFound();
        $this->actingAs($admin)->delete('/offices/abc')->assertNotFound();
    }
}