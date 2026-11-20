<?php

declare(strict_types=1);

namespace Tests\Feature\OfficeNetwork;

use App\Models\DeviceConnectionSession;
use App\Models\Member;
use App\Models\MemberDeviceAssignment;
use App\Models\RegisteredDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemberAssignmentTest extends TestCase
{
    use BuildsOfficeNetwork;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);
    }

    // ---------------------------------------------------------------- access

    public function test_guests_cannot_change_anything(): void
    {
        $this->post('/members', ['full_name' => 'X'])->assertRedirect();
        $this->post('/registered-devices', ['name' => 'X'])->assertRedirect();
        $this->post('/registered-devices/1/assign', ['member_id' => 1])->assertRedirect();
        $this->delete('/registered-devices/1/assignment')->assertRedirect();
    }

    public function test_only_a_super_administrator_can_manage_members_devices_and_assignments(): void
    {
        $office = $this->makeOffice();
        $member = $this->makeMember($office);
        $other = $this->makeMember($office, 'Pedro Reyes');
        $device = $this->makeDevice($member);

        foreach (['network_admin', 'viewer'] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)->post('/members', ['office_id' => $office->id, 'full_name' => 'Sneaky'])->assertForbidden();
            $this->actingAs($user)->patch("/members/{$member->id}", ['office_id' => $office->id, 'full_name' => 'Renamed'])->assertForbidden();
            $this->actingAs($user)->post('/registered-devices', ['name' => 'Sneaky', 'device_type' => 'laptop', 'mac_address' => 'AA:AA:AA:AA:AA:AA'])->assertForbidden();
            $this->actingAs($user)->patch("/registered-devices/{$device->id}", ['name' => 'Renamed', 'device_type' => 'laptop'])->assertForbidden();
            $this->actingAs($user)->post("/registered-devices/{$device->id}/assign", ['member_id' => $other->id])->assertForbidden();
            $this->actingAs($user)->delete("/registered-devices/{$device->id}/assignment")->assertForbidden();
        }

        $this->assertSame(2, Member::count());
        $this->assertSame(1, RegisteredDevice::count());
        $this->assertSame($member->id, $this->currentAssignment($device)?->member_id);
        $this->assertSame('Laptop', $device->refresh()->name);
    }

    // --------------------------------------------------------------- members

    public function test_a_member_can_be_added_and_it_is_audited(): void
    {
        $office = $this->makeOffice();

        $this->actingAs($this->userWithRole('super_admin'))
            ->post('/members', ['office_id' => $office->id, 'full_name' => 'Maria Santos', 'employee_id' => 'E-001', 'position' => 'Instructor'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('members', ['full_name' => 'Maria Santos', 'employee_id' => 'E-001', 'office_id' => $office->id, 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.created']);
    }

    public function test_member_fields_are_validated(): void
    {
        $office = $this->makeOffice();
        $inactive = $this->makeOffice('Closed office', ['is_active' => false]);
        $this->makeMember($office, 'Existing')->update(['employee_id' => 'E-001']);
        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)->post('/members', ['office_id' => $office->id])->assertSessionHasErrors('full_name');
        $this->actingAs($admin)->post('/members', ['full_name' => 'No office'])->assertSessionHasErrors('office_id');
        $this->actingAs($admin)->post('/members', ['office_id' => 999999, 'full_name' => 'Ghost office'])->assertSessionHasErrors('office_id');
        $this->actingAs($admin)->post('/members', ['office_id' => $inactive->id, 'full_name' => 'Inactive office'])->assertSessionHasErrors('office_id');
        $this->actingAs($admin)->post('/members', ['office_id' => $office->id, 'full_name' => 'Dup', 'employee_id' => 'E-001'])->assertSessionHasErrors('employee_id');

        $this->assertSame(1, Member::count());
    }

    public function test_a_member_can_be_edited_and_saving_the_same_values_is_not_audited(): void
    {
        $office = $this->makeOffice();
        $member = $this->makeMember($office, 'Maria Santos');
        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)
            ->patch("/members/{$member->id}", ['office_id' => $office->id, 'full_name' => 'Maria Santos'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'member.updated']);

        $this->actingAs($admin)
            ->patch("/members/{$member->id}", ['office_id' => $office->id, 'full_name' => 'Maria S. Santos', 'position' => 'Dean'])
            ->assertRedirect();

        $this->assertSame('Maria S. Santos', $member->refresh()->full_name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.updated']);
    }

    public function test_a_member_with_devices_cannot_be_deactivated_until_they_are_unassigned(): void
    {
        $office = $this->makeOffice();
        $member = $this->makeMember($office);
        $device = $this->makeDevice($member);
        $admin = $this->userWithRole('super_admin');
        $payload = ['office_id' => $office->id, 'full_name' => $member->full_name, 'is_active' => false];

        $this->actingAs($admin)->patch("/members/{$member->id}", $payload)->assertSessionHas('error');
        $this->assertTrue($member->refresh()->is_active);

        $this->actingAs($admin)->delete("/registered-devices/{$device->id}/assignment")->assertSessionHas('success');
        $this->actingAs($admin)->patch("/members/{$member->id}", $payload)->assertSessionHas('success');

        $this->assertFalse($member->refresh()->is_active);
    }

    public function test_moving_a_member_to_another_office_moves_their_open_connections(): void
    {
        $cids = $this->makeOffice('CIDS Office');
        $registrar = $this->makeOffice('Registrar');
        $member = $this->makeMember($cids);
        $device = $this->makeDevice($member);
        $open = $this->connect($device);
        $closed = $this->connect($device, ['ended_at' => now()->subHour(), 'last_seen_at' => now()->subHour()]);

        $this->actingAs($this->userWithRole('super_admin'))
            ->patch("/members/{$member->id}", ['office_id' => $registrar->id, 'full_name' => $member->full_name])
            ->assertRedirect();

        $this->assertSame($registrar->id, $open->refresh()->office_id);
        $this->assertSame($cids->id, $closed->refresh()->office_id); // history is not rewritten
    }

    // --------------------------------------------------------------- devices

    public function test_a_device_is_registered_with_a_normalised_mac_and_open_connections_are_linked(): void
    {
        $office = $this->makeOffice();
        $open = $this->connect(null, ['mac_address' => 'AA:BB:CC:00:00:77']);
        $closed = $this->connect(null, ['mac_address' => 'AA:BB:CC:00:00:77', 'ended_at' => now()->subDay(), 'last_seen_at' => now()->subDay()]);

        $this->actingAs($this->userWithRole('super_admin'))
            ->post('/registered-devices', [
                'name' => 'Printer',
                'device_type' => 'printer',
                'mac_address' => 'aa-bb-cc-00-00-77',
                'office_id' => $office->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $device = RegisteredDevice::query()->where('mac_address', 'AA:BB:CC:00:00:77')->firstOrFail();

        $this->assertSame($device->id, $open->refresh()->registered_device_id);
        $this->assertSame($office->id, $open->office_id);
        $this->assertNull($closed->refresh()->registered_device_id); // already closed: left as recorded
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.registered']);
    }

    public function test_device_fields_are_validated(): void
    {
        $this->makeDevice($this->makeMember($this->makeOffice()))->update(['mac_address' => 'AA:BB:CC:00:00:01']);
        $admin = $this->userWithRole('super_admin');
        $valid = ['name' => 'Laptop', 'device_type' => 'laptop', 'mac_address' => 'AA:BB:CC:00:00:99'];

        $this->actingAs($admin)->post('/registered-devices', [...$valid, 'mac_address' => 'not-a-mac'])->assertSessionHasErrors('mac_address');
        $this->actingAs($admin)->post('/registered-devices', [...$valid, 'mac_address' => 'AA:BB:CC'])->assertSessionHasErrors('mac_address');
        $this->actingAs($admin)->post('/registered-devices', [...$valid, 'mac_address' => 'aa-bb-cc-00-00-01'])->assertSessionHasErrors('mac_address');
        $this->actingAs($admin)->post('/registered-devices', [...$valid, 'name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/registered-devices', [...$valid, 'device_type' => 'toaster'])->assertSessionHasErrors('device_type');
        $this->actingAs($admin)->post('/registered-devices', [...$valid, 'office_id' => 999999])->assertSessionHasErrors('office_id');

        $this->assertSame(1, RegisteredDevice::count());
    }

    public function test_the_mac_address_cannot_be_changed_after_registration(): void
    {
        $device = $this->makeDevice($this->makeMember($this->makeOffice()));
        $mac = $device->mac_address;

        $this->actingAs($this->userWithRole('super_admin'))
            ->patch("/registered-devices/{$device->id}", [
                'name' => 'Renamed',
                'device_type' => 'laptop',
                'mac_address' => 'FF:FF:FF:FF:FF:FF',
            ])
            ->assertRedirect();

        $this->assertSame('Renamed', $device->refresh()->name);
        $this->assertSame($mac, $device->mac_address);
    }

    public function test_a_private_wifi_address_is_flagged_when_registering(): void
    {
        // The second hex digit of the first byte is 2, 6, A or E: a locally administered (randomised) address.
        $this->actingAs($this->userWithRole('super_admin'))
            ->post('/registered-devices', ['name' => 'Phone', 'device_type' => 'phone', 'mac_address' => '02:11:22:33:44:55'])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'private'));
    }

    public function test_deactivating_a_device_also_unassigns_it(): void
    {
        $device = $this->makeDevice($this->makeMember($this->makeOffice()));

        $this->actingAs($this->userWithRole('super_admin'))
            ->patch("/registered-devices/{$device->id}", ['name' => $device->name, 'device_type' => 'laptop', 'is_active' => false])
            ->assertRedirect();

        $this->assertFalse($device->refresh()->is_active);
        $this->assertNull($this->currentAssignment($device));
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.unassigned']);
    }

    // ----------------------------------------------------------- assignments

    public function test_assigning_a_device_updates_its_open_connections_and_is_audited(): void
    {
        $office = $this->makeOffice('CIDS Office');
        $member = $this->makeMember($office, 'Maria Santos');
        $device = RegisteredDevice::create(['name' => 'Laptop', 'device_type' => 'laptop', 'mac_address' => 'AA:BB:CC:00:00:50']);
        $open = $this->connect($device);

        $this->assertNull($open->member_id);

        $this->actingAs($this->userWithRole('super_admin'))
            ->post("/registered-devices/{$device->id}/assign", ['member_id' => $member->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($member->id, $this->currentAssignment($device)?->member_id);
        $this->assertSame($member->id, $open->refresh()->member_id);
        $this->assertSame($office->id, $open->office_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.assigned']);
    }

    public function test_reassigning_keeps_exactly_one_open_assignment_and_does_not_rewrite_history(): void
    {
        $cids = $this->makeOffice('CIDS Office');
        $registrar = $this->makeOffice('Registrar');
        $maria = $this->makeMember($cids, 'Maria Santos');
        $ana = $this->makeMember($registrar, 'Ana Cruz');
        $device = $this->makeDevice($maria);
        $open = $this->connect($device);
        $closed = $this->connect($device, ['ended_at' => now()->subHour(), 'last_seen_at' => now()->subHour()]);

        $this->actingAs($this->userWithRole('super_admin'))
            ->post("/registered-devices/{$device->id}/assign", ['member_id' => $ana->id])
            ->assertRedirect();

        $this->assertSame(1, MemberDeviceAssignment::query()->where('registered_device_id', $device->id)->whereNull('unassigned_at')->count());
        $this->assertSame(2, MemberDeviceAssignment::query()->where('registered_device_id', $device->id)->count());
        $this->assertSame($ana->id, $this->currentAssignment($device)?->member_id);

        $this->assertSame($ana->id, $open->refresh()->member_id);
        $this->assertSame($registrar->id, $open->office_id);
        $this->assertSame($maria->id, $closed->refresh()->member_id);
        $this->assertSame($cids->id, $closed->office_id);
    }

    public function test_assigning_to_the_same_member_again_changes_nothing(): void
    {
        $member = $this->makeMember($this->makeOffice());
        $device = $this->makeDevice($member);

        $this->actingAs($this->userWithRole('super_admin'))
            ->post("/registered-devices/{$device->id}/assign", ['member_id' => $member->id])
            ->assertRedirect();

        $this->assertSame(1, MemberDeviceAssignment::query()->where('registered_device_id', $device->id)->count());
    }

    public function test_unassigning_closes_the_assignment_and_clears_the_member_of_open_connections(): void
    {
        $member = $this->makeMember($this->makeOffice());
        $device = $this->makeDevice($member);
        $open = $this->connect($device);
        $closed = $this->connect($device, ['ended_at' => now()->subHour(), 'last_seen_at' => now()->subHour()]);

        $this->actingAs($this->userWithRole('super_admin'))
            ->delete("/registered-devices/{$device->id}/assignment")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull($this->currentAssignment($device));
        $this->assertNotNull(MemberDeviceAssignment::query()->where('registered_device_id', $device->id)->first()->unassigned_at);
        $this->assertNull($open->refresh()->member_id);
        $this->assertSame($member->id, $closed->refresh()->member_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.unassigned']);
    }

    public function test_unassigning_a_device_that_is_not_assigned_reports_an_error(): void
    {
        $device = RegisteredDevice::create(['name' => 'Laptop', 'device_type' => 'laptop', 'mac_address' => 'AA:BB:CC:00:00:51']);

        $this->actingAs($this->userWithRole('super_admin'))
            ->delete("/registered-devices/{$device->id}/assignment")
            ->assertSessionHas('error');
    }

    public function test_a_device_cannot_be_assigned_to_an_inactive_or_unknown_member_or_while_inactive(): void
    {
        $office = $this->makeOffice();
        $active = $this->makeMember($office, 'Active');
        $inactive = $this->makeMember($office, 'Inactive', active: false);
        $device = RegisteredDevice::create(['name' => 'Laptop', 'device_type' => 'laptop', 'mac_address' => 'AA:BB:CC:00:00:52']);
        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)->post("/registered-devices/{$device->id}/assign", ['member_id' => $inactive->id])->assertSessionHasErrors('member_id');
        $this->actingAs($admin)->post("/registered-devices/{$device->id}/assign", ['member_id' => 999999])->assertSessionHasErrors('member_id');
        $this->actingAs($admin)->post("/registered-devices/{$device->id}/assign", [])->assertSessionHasErrors('member_id');

        $device->update(['is_active' => false]);
        $this->actingAs($admin)->post("/registered-devices/{$device->id}/assign", ['member_id' => $active->id])->assertSessionHas('error');

        $this->assertNull($this->currentAssignment($device));
    }

    public function test_the_ids_in_the_urls_must_be_numeric(): void
    {
        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)->patch('/members/abc', ['full_name' => 'X'])->assertNotFound();
        $this->actingAs($admin)->patch('/registered-devices/abc', ['name' => 'X'])->assertNotFound();
        $this->actingAs($admin)->post('/registered-devices/abc/assign', ['member_id' => 1])->assertNotFound();
        $this->actingAs($this->userWithRole('network_admin'))->get('/members/abc')->assertNotFound();
    }

    // ----------------------------------------------------------------- pages

    public function test_a_member_page_lists_devices_and_connection_history_and_is_audited(): void
    {
        $member = $this->makeMember($this->makeOffice('CIDS Office'), 'Maria Santos');
        $device = $this->makeDevice($member, 'Maria laptop');
        $this->connect($device);
        $this->connect($device, ['started_at' => now()->subDays(2), 'last_seen_at' => now()->subDays(2), 'ended_at' => now()->subDays(2)]);

        $this->actingAs($this->userWithRole('network_admin'))
            ->get("/members/{$member->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('members/show')
                ->where('member.full_name', 'Maria Santos')
                ->where('member.office_name', 'CIDS Office')
                ->has('devices', 1)
                ->where('devices.0.device_name', 'Maria laptop')
                ->where('devices.0.current', true)
                ->where('connections.total', 2));

        $this->assertDatabaseHas('audit_logs', ['action' => 'office_network.member_viewed']);
    }

    public function test_the_members_list_can_be_filtered(): void
    {
        $cids = $this->makeOffice('CIDS Office');
        $registrar = $this->makeOffice('Registrar');
        $this->makeMember($cids, 'Maria Santos');
        $this->makeMember($registrar, 'Ana Cruz');
        $this->makeMember($registrar, 'Retired Person', active: false);
        $user = $this->userWithRole('network_admin');

        $this->actingAs($user)->get('/members')->assertInertia(fn (Assert $page) => $page->where('members.total', 3));
        $this->actingAs($user)->get('/members?search=maria')->assertInertia(fn (Assert $page) => $page->where('members.total', 1)->where('members.data.0.full_name', 'Maria Santos'));
        $this->actingAs($user)->get("/members?office={$registrar->id}")->assertInertia(fn (Assert $page) => $page->where('members.total', 2));
        $this->actingAs($user)->get('/members?status=inactive')->assertInertia(fn (Assert $page) => $page->where('members.total', 1)->where('members.data.0.full_name', 'Retired Person'));
        $this->actingAs($user)->get('/members?status=banana')->assertSessionHasErrors('status');
    }

    public function test_the_devices_list_can_be_filtered_and_shows_the_effective_office(): void
    {
        $cids = $this->makeOffice('CIDS Office');
        $this->makeDevice($this->makeMember($cids, 'Maria Santos'), 'Maria laptop');
        RegisteredDevice::create(['name' => 'Spare phone', 'device_type' => 'phone', 'mac_address' => 'AA:BB:CC:00:00:60']);
        $user = $this->userWithRole('network_admin');

        $this->actingAs($user)->get('/registered-devices?assignment=assigned')
            ->assertInertia(fn (Assert $page) => $page
                ->where('devices.total', 1)
                ->where('devices.data.0.member_name', 'Maria Santos')
                ->where('devices.data.0.office_name', 'CIDS Office'));

        $this->actingAs($user)->get('/registered-devices?assignment=unassigned')
            ->assertInertia(fn (Assert $page) => $page->where('devices.total', 1)->where('devices.data.0.name', 'Spare phone'));

        $this->actingAs($user)->get('/registered-devices?search=maria')
            ->assertInertia(fn (Assert $page) => $page->where('devices.total', 1)->where('devices.data.0.name', 'Maria laptop'));

        $this->actingAs($user)->get('/registered-devices?type=phone')
            ->assertInertia(fn (Assert $page) => $page->where('devices.total', 1));

        $this->actingAs($user)->get('/registered-devices?type=toaster')->assertSessionHasErrors('type');
    }

    public function test_the_register_button_prefills_the_form_for_a_super_administrator_only(): void
    {
        $url = '/registered-devices?register_mac=AA:BB:CC:00:00:70&register_name=android-1234';

        $this->actingAs($this->userWithRole('super_admin'))->get($url)
            ->assertInertia(fn (Assert $page) => $page->where('prefill.mac', 'AA:BB:CC:00:00:70')->where('prefill.name', 'android-1234'));

        $this->actingAs($this->userWithRole('network_admin'))->get($url)
            ->assertInertia(fn (Assert $page) => $page->where('prefill', null));
    }

    public function test_assigned_devices_are_counted_in_their_members_office_on_the_office_page(): void
    {
        $this->enableDhcpSource();
        $cids = $this->makeOffice('CIDS Office');
        $member = $this->makeMember($cids, 'Maria Santos');
        $device = RegisteredDevice::create(['name' => 'Laptop', 'device_type' => 'laptop', 'mac_address' => 'AA:BB:CC:00:00:80']);
        $this->connect($device);

        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page->where('offices.0.registered_devices', 0)->where('offices.0.online_devices', 0));

        $this->actingAs($admin)->post("/registered-devices/{$device->id}/assign", ['member_id' => $member->id])->assertRedirect();

        $this->actingAs($admin)->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page->where('offices.0.registered_devices', 1)->where('offices.0.online_devices', 1));

        $this->assertSame(1, DeviceConnectionSession::query()->where('office_id', $cids->id)->count());
    }

    private function currentAssignment(RegisteredDevice $device): ?MemberDeviceAssignment
    {
        return MemberDeviceAssignment::query()
            ->where('registered_device_id', $device->id)
            ->whereNull('unassigned_at')
            ->first();
    }
}