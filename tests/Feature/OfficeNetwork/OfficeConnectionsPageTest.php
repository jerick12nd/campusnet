<?php

declare(strict_types=1);

namespace Tests\Feature\OfficeNetwork;

use App\Models\ActivityDataSource;
use App\Models\DeviceConnectionSession;
use App\Models\MemberDeviceAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OfficeConnectionsPageTest extends TestCase
{
    use BuildsOfficeNetwork;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);
    }

    // ---------------------------------------------------------------- access

    public function test_guests_are_redirected(): void
    {
        $office = $this->makeOffice();

        $this->get('/office-connections')->assertRedirect();
        $this->get("/office-connections/{$office->id}")->assertRedirect();
    }

    public function test_every_role_can_open_the_list_with_the_right_abilities(): void
    {
        $this->makeOffice();

        $expected = [
            'viewer' => [false, false],
            'network_admin' => [false, true],
            'super_admin' => [true, true],
        ];

        foreach ($expected as $role => [$manage, $members]) {
            $this->actingAs($this->userWithRole($role))
                ->get('/office-connections')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('office-connections/index')
                    ->where('can.manage', $manage)
                    ->where('can.members', $members));
        }
    }

    public function test_the_office_id_in_the_url_must_be_numeric(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->get('/office-connections/abc')
            ->assertNotFound();
    }

    public function test_an_archived_office_is_not_found(): void
    {
        $office = $this->makeOffice();
        $office->delete();

        $this->actingAs($this->userWithRole('super_admin'))
            ->get("/office-connections/{$office->id}")
            ->assertNotFound();
    }

    public function test_a_viewer_sees_office_totals_but_no_member_level_data(): void
    {
        $this->enableDhcpSource();
        $office = $this->makeOffice();
        $device = $this->makeDevice($this->makeMember($office, 'Maria Santos'), 'Maria laptop');
        $this->connect($device);

        $viewer = $this->userWithRole('viewer');

        // Even with ?state=history in the URL the viewer gets no rows, and nothing leaks into the page.
        foreach (['', '?state=history', '?state=all'] as $query) {
            $this->actingAs($viewer)
                ->get("/office-connections/{$office->id}{$query}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('office-connections/show')
                    ->where('connections', null)
                    ->where('can.members', false)
                    ->where('office.online_devices', 1))
                ->assertDontSee('Maria Santos')
                ->assertDontSee($device->mac_address);
        }

        $this->assertDatabaseMissing('audit_logs', ['action' => 'office_network.office_viewed']);
    }

    public function test_an_ict_administrator_sees_member_level_connections_and_the_view_is_audited(): void
    {
        $this->enableDhcpSource();
        $office = $this->makeOffice();
        $device = $this->makeDevice($this->makeMember($office, 'Maria Santos'), 'Maria laptop');
        $this->connect($device);

        $this->actingAs($this->userWithRole('network_admin'))
            ->get("/office-connections/{$office->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.members', true)
                ->has('connections.data', 1)
                ->where('connections.data.0.member_name', 'Maria Santos')
                ->where('connections.data.0.device_name', 'Maria laptop')
                ->where('connections.data.0.mac_address', $device->mac_address)
                ->where('connections.data.0.status', 'online'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'office_network.office_viewed']);
    }

    public function test_an_invalid_connection_state_is_rejected(): void
    {
        $office = $this->makeOffice();

        $this->actingAs($this->userWithRole('network_admin'))
            ->get("/office-connections/{$office->id}?state=everything")
            ->assertSessionHasErrors('state');
    }

    // ---------------------------------------------------------------- counts

    public function test_counts_come_from_stored_sessions_per_office(): void
    {
        $this->enableDhcpSource();

        $cids = $this->makeOffice('CIDS Office', ['code' => 'CIDS']);
        $registrar = $this->makeOffice('Registrar', ['code' => 'REG']);
        $this->makeOffice('Library');

        $maria = $this->makeMember($cids, 'Maria Santos');
        $pedro = $this->makeMember($cids, 'Pedro Reyes');
        $ana = $this->makeMember($registrar, 'Ana Cruz');

        $online = $this->makeDevice($maria, 'Maria laptop');
        $stale = $this->makeDevice($maria, 'Maria phone');
        $closed = $this->makeDevice($pedro, 'Pedro laptop');
        $this->makeDevice($ana, 'Ana desktop');

        $this->connect($online);
        $this->connect($stale, ['last_seen_at' => now()->subMinutes(10)]);
        $this->connect($closed, ['ended_at' => now()->subMinutes(2), 'last_seen_at' => now()->subMinutes(2)]);

        // Not registered: counted as unidentified, never attributed to an office or a member.
        $this->connect(null);
        $this->connect(null, ['last_seen_at' => now()->subHour()]);

        // Offices are listed by name: CIDS Office, Library, Registrar.
        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/office-connections')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('offices.0.name', 'CIDS Office')
                ->where('offices.0.members', 2)
                ->where('offices.0.registered_devices', 3)
                ->where('offices.0.online_devices', 1)
                ->where('offices.0.offline_devices', 2)
                ->where('offices.0.status', 'active')
                ->where('offices.1.name', 'Library')
                ->where('offices.1.registered_devices', 0)
                ->where('offices.1.status', 'no_devices')
                ->where('offices.2.name', 'Registrar')
                ->where('offices.2.registered_devices', 1)
                ->where('offices.2.online_devices', 0)
                ->where('offices.2.offline_devices', 1)
                ->where('offices.2.status', 'idle')
                ->where('totals.offices', 3)
                ->where('totals.online_devices', 1)
                ->where('totals.registered_devices', 4)
                ->where('totals.unidentified_online', 1));
    }

    public function test_a_device_belongs_to_the_office_of_its_current_assignment(): void
    {
        $this->enableDhcpSource();

        $cids = $this->makeOffice('CIDS Office');
        $registrar = $this->makeOffice('Registrar');
        $device = $this->makeDevice($this->makeMember($cids, 'Maria Santos'));

        // Reassign: close the old row, open a new one for a member of another office.
        MemberDeviceAssignment::query()->where('registered_device_id', $device->id)->update(['unassigned_at' => now()]);
        MemberDeviceAssignment::create([
            'registered_device_id' => $device->id,
            'member_id' => $this->makeMember($registrar, 'Ana Cruz')->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page
                ->where('offices.0.name', 'CIDS Office')
                ->where('offices.0.registered_devices', 0)
                ->where('offices.1.name', 'Registrar')
                ->where('offices.1.registered_devices', 1));
    }

    public function test_a_device_with_two_open_sessions_is_counted_once(): void
    {
        $this->enableDhcpSource();
        $office = $this->makeOffice();
        $device = $this->makeDevice($this->makeMember($office));

        $this->connect($device, ['ip_address' => '192.168.10.50']);
        $this->connect($device, ['ip_address' => '192.168.10.51']);

        $this->actingAs($this->userWithRole('viewer'))
            ->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page
                ->where('offices.0.online_devices', 1)
                ->where('offices.0.offline_devices', 0));
    }

    // ------------------------------------------------------ connection status

    public function test_the_online_window_follows_the_configured_threshold(): void
    {
        $this->enableDhcpSource();
        $office = $this->makeOffice();
        $device = $this->makeDevice($this->makeMember($office));
        $this->connect($device, ['last_seen_at' => now()->subSeconds(90)]);

        $viewer = $this->userWithRole('viewer');

        config(['office_network.online_threshold_seconds' => 60]);
        $this->actingAs($viewer)->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page->where('offices.0.online_devices', 0)->where('offices.0.status', 'idle'));

        config(['office_network.online_threshold_seconds' => 120]);
        $this->actingAs($viewer)->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page->where('offices.0.online_devices', 1)->where('offices.0.status', 'active'));
    }

    public function test_a_closed_session_is_never_online_even_if_seen_a_moment_ago(): void
    {
        $this->enableDhcpSource();
        $office = $this->makeOffice();
        $device = $this->makeDevice($this->makeMember($office));
        $this->connect($device, ['ended_at' => now()->subSeconds(5)]);

        $this->actingAs($this->userWithRole('viewer'))
            ->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page->where('offices.0.online_devices', 0));
    }

    public function test_the_connection_list_separates_online_history_and_all(): void
    {
        $this->enableDhcpSource();
        $office = $this->makeOffice();
        $member = $this->makeMember($office);

        $this->connect($this->makeDevice($member, 'Online one'));
        $this->connect($this->makeDevice($member, 'Stale one'), ['last_seen_at' => now()->subMinutes(10)]);
        $this->connect($this->makeDevice($member, 'Closed one'), ['ended_at' => now()->subMinutes(5)]);

        $admin = $this->userWithRole('network_admin');
        $url = "/office-connections/{$office->id}";

        $this->actingAs($admin)->get($url)
            ->assertInertia(fn (Assert $page) => $page
                ->where('connections.total', 1)
                ->where('connections.data.0.device_name', 'Online one'));

        $this->actingAs($admin)->get("{$url}?state=history")
            ->assertInertia(fn (Assert $page) => $page
                ->where('connections.total', 2)
                ->where('connections.data.0.status', 'offline')
                ->where('connections.data.1.status', 'offline'));

        $this->actingAs($admin)->get("{$url}?state=all")
            ->assertInertia(fn (Assert $page) => $page->where('connections.total', 3));
    }

    // ---------------------------------------------------- unidentified devices

    public function test_an_unregistered_device_is_never_guessed_to_be_a_member(): void
    {
        $this->enableDhcpSource();
        $office = $this->makeOffice();
        $this->makeMember($office, 'Maria Santos');

        $session = $this->connect(null, ['hostname' => 'android-1234']);

        $this->assertNull($session->member_id);
        $this->assertNull($session->office_id);

        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.unidentified_online', 1)
                ->where('offices.0.online_devices', 0)
                ->where('offices.0.registered_devices', 0));

        $this->assertSame(1, DeviceConnectionSession::unidentified()->count());
    }

    // -------------------------------------------------- traffic and telemetry

    public function test_traffic_is_only_reported_when_a_source_measured_it(): void
    {
        $this->enableDhcpSource();
        $office = $this->makeOffice();
        $device = $this->makeDevice($this->makeMember($office));
        $session = $this->connect($device);

        $viewer = $this->userWithRole('viewer');

        // DHCP cannot measure bytes: they stay null, never zero or an estimate.
        $this->actingAs($viewer)->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page->where('offices.0.rx_bytes', null)->where('offices.0.tx_bytes', null));

        $session->update(['rx_bytes' => 1000, 'tx_bytes' => 500]);

        $this->actingAs($viewer)->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page->where('offices.0.rx_bytes', 1000)->where('offices.0.tx_bytes', 500));
    }

    public function test_without_an_enabled_source_the_office_says_so_instead_of_counting(): void
    {
        $this->makeOffice();

        $this->actingAs($this->userWithRole('viewer'))
            ->get('/office-connections')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('source.enabled', false)
                ->where('offices.0.status', 'no_source')
                ->where('offices.0.rx_bytes', null));
    }

    public function test_only_the_dhcp_source_decides_whether_connection_data_exists(): void
    {
        // A DNS log says nothing about who is connected, so enabling it alone must not count.
        ActivityDataSource::create([
            'key' => 'mikrotik_dns_log',
            'name' => 'MikroTik DNS query log',
            'type' => 'dns',
            'is_enabled' => true,
        ]);
        $this->makeOffice();

        $this->actingAs($this->userWithRole('viewer'))
            ->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page->where('source.enabled', false));
    }

    public function test_a_failing_or_stale_source_is_reported(): void
    {
        $source = $this->enableDhcpSource();
        $this->makeOffice();
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page
                ->where('source.enabled', true)->where('source.stale', false)->where('source.failing', false));

        $source->update(['last_status' => 'failed']);
        $this->actingAs($viewer)->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page->where('source.failing', true));

        $source->update(['last_status' => 'ok', 'last_run_at' => now()->subHour()]);
        $this->actingAs($viewer)->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page->where('source.stale', true));

        $source->update(['last_run_at' => null]);
        $this->actingAs($viewer)->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page->where('source.stale', true)->where('source.last_run_at', null));
    }

    public function test_an_empty_installation_renders_without_errors(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->get('/office-connections')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('offices', 0)
                ->where('totals.offices', 0)
                ->where('totals.unidentified_online', 0)
                ->where('source.enabled', false));
    }

    // --------------------------------------------------------------- filters

    public function test_offices_can_be_searched_and_filtered_by_status(): void
    {
        $this->enableDhcpSource();

        $cids = $this->makeOffice('CIDS Office', ['code' => 'CIDS', 'department' => 'Computing']);
        $registrar = $this->makeOffice('Registrar', ['code' => 'REG']);
        $this->makeOffice('Library');

        $this->connect($this->makeDevice($this->makeMember($cids)));
        $this->makeDevice($this->makeMember($registrar, 'Ana Cruz'));

        $user = $this->userWithRole('network_admin');

        $this->actingAs($user)->get('/office-connections?search=cids')
            ->assertInertia(fn (Assert $page) => $page->has('offices', 1)->where('offices.0.name', 'CIDS Office'));

        $this->actingAs($user)->get('/office-connections?search=Computing')
            ->assertInertia(fn (Assert $page) => $page->has('offices', 1)->where('offices.0.name', 'CIDS Office'));

        $this->actingAs($user)->get('/office-connections?search=REG')
            ->assertInertia(fn (Assert $page) => $page->has('offices', 1)->where('offices.0.name', 'Registrar'));

        $this->actingAs($user)->get('/office-connections?status=active')
            ->assertInertia(fn (Assert $page) => $page->has('offices', 1)->where('offices.0.name', 'CIDS Office'));

        $this->actingAs($user)->get('/office-connections?status=idle')
            ->assertInertia(fn (Assert $page) => $page->has('offices', 1)->where('offices.0.name', 'Registrar'));

        $this->actingAs($user)->get('/office-connections?status=no_devices')
            ->assertInertia(fn (Assert $page) => $page->has('offices', 1)->where('offices.0.name', 'Library'));
    }

    public function test_invalid_filters_are_rejected_and_search_is_never_sql(): void
    {
        $this->makeOffice();
        $user = $this->userWithRole('network_admin');

        $this->actingAs($user)->get('/office-connections?status=banana')->assertSessionHasErrors('status');
        $this->actingAs($user)->get('/office-connections?search='.str_repeat('a', 101))->assertSessionHasErrors('search');

        $this->actingAs($user)->get('/office-connections?search='.urlencode("' OR 1=1 --"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('offices', 0));
    }

    public function test_inactive_offices_are_left_out_of_the_list(): void
    {
        $this->makeOffice('Old office', ['is_active' => false]);
        $this->makeOffice('Current office');

        $this->actingAs($this->userWithRole('viewer'))
            ->get('/office-connections')
            ->assertInertia(fn (Assert $page) => $page->has('offices', 1)->where('offices.0.name', 'Current office'));
    }
}