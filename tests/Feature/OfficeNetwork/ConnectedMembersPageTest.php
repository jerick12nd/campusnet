<?php

declare(strict_types=1);

namespace Tests\Feature\OfficeNetwork;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConnectedMembersPageTest extends TestCase
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
        $this->get('/connected-members')->assertRedirect();
        $this->get('/connected-members/export')->assertRedirect();
        $this->get('/members')->assertRedirect();
        $this->get('/registered-devices')->assertRedirect();
    }

    public function test_a_viewer_cannot_reach_any_member_level_page_or_export(): void
    {
        $member = $this->makeMember($this->makeOffice());
        $viewer = $this->userWithRole('viewer');

        foreach (['/connected-members', '/connected-members/export', '/members', "/members/{$member->id}", '/registered-devices'] as $url) {
            $this->actingAs($viewer)->get($url)->assertForbidden();
        }
    }

    public function test_an_ict_administrator_can_read_but_not_manage(): void
    {
        $admin = $this->userWithRole('network_admin');

        $this->actingAs($admin)->get('/connected-members')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('connected-members/index')
                ->where('can.manage', false)
                ->where('can.export', true));

        $this->actingAs($admin)->get('/members')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('members/index')->where('can.manage', false));

        $this->actingAs($admin)->get('/registered-devices')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('registered-devices/index')
                ->where('can.manage', false)
                ->where('members', [])
                ->where('offices', []));
    }

    public function test_a_super_administrator_gets_the_management_abilities(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))->get('/connected-members')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', true)->where('can.export', true));
    }

    // ----------------------------------------------------------------- list

    public function test_each_device_appears_once_with_its_latest_connection(): void
    {
        $this->enableDhcpSource();
        $device = $this->makeDevice($this->makeMember($this->makeOffice('CIDS Office'), 'Maria Santos'), 'Maria laptop');

        $this->connect($device, ['started_at' => now()->subDay(), 'last_seen_at' => now()->subDay(), 'ended_at' => now()->subDay()]);
        $this->connect($device);

        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/connected-members')
            ->assertInertia(fn (Assert $page) => $page
                ->where('connections.total', 1)
                ->where('connections.data.0.member_name', 'Maria Santos')
                ->where('connections.data.0.office_name', 'CIDS Office')
                ->where('connections.data.0.device_name', 'Maria laptop')
                ->where('connections.data.0.identified', true)
                ->where('connections.data.0.status', 'online'));
    }

    public function test_an_unregistered_device_is_listed_as_unidentified(): void
    {
        $this->enableDhcpSource();
        $this->makeMember($this->makeOffice(), 'Maria Santos');
        $this->connect(null, ['hostname' => 'android-1234']);

        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/connected-members')
            ->assertInertia(fn (Assert $page) => $page
                ->where('connections.total', 1)
                ->where('connections.data.0.identified', false)
                ->where('connections.data.0.member_name', null)
                ->where('connections.data.0.office_name', null)
                ->where('connections.data.0.hostname', 'android-1234')
                ->where('summary.online_unidentified', 1)
                ->where('summary.online_identified', 0));
    }

    public function test_an_open_but_stale_connection_is_offline(): void
    {
        $this->enableDhcpSource();
        $device = $this->makeDevice($this->makeMember($this->makeOffice()));
        $this->connect($device, ['last_seen_at' => now()->subMinutes(30)]);

        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/connected-members')
            ->assertInertia(fn (Assert $page) => $page->where('connections.data.0.status', 'offline'));
    }

    public function test_connections_can_be_filtered_and_searched(): void
    {
        $this->enableDhcpSource();

        $cids = $this->makeOffice('CIDS Office');
        $registrar = $this->makeOffice('Registrar');

        $maria = $this->makeDevice($this->makeMember($cids, 'Maria Santos'), 'Maria laptop');
        $ana = $this->makeDevice($this->makeMember($registrar, 'Ana Cruz'), 'Ana phone');
        $ana->update(['device_type' => 'phone']);

        $this->connect($maria);
        $this->connect($ana, ['ended_at' => now()->subMinutes(5), 'last_seen_at' => now()->subMinutes(5)]);
        $this->connect(null, ['hostname' => 'unknown-host']);

        $user = $this->userWithRole('network_admin');

        $this->actingAs($user)->get('/connected-members')
            ->assertInertia(fn (Assert $page) => $page->where('connections.total', 3));

        $this->actingAs($user)->get("/connected-members?office={$cids->id}")
            ->assertInertia(fn (Assert $page) => $page->where('connections.total', 1)->where('connections.data.0.member_name', 'Maria Santos'));

        $this->actingAs($user)->get('/connected-members?status=online')
            ->assertInertia(fn (Assert $page) => $page->where('connections.total', 2));

        $this->actingAs($user)->get('/connected-members?status=offline')
            ->assertInertia(fn (Assert $page) => $page->where('connections.total', 1)->where('connections.data.0.member_name', 'Ana Cruz'));

        $this->actingAs($user)->get('/connected-members?status=unidentified')
            ->assertInertia(fn (Assert $page) => $page->where('connections.total', 1)->where('connections.data.0.identified', false));

        $this->actingAs($user)->get('/connected-members?type=phone')
            ->assertInertia(fn (Assert $page) => $page->where('connections.total', 1)->where('connections.data.0.member_name', 'Ana Cruz'));

        $this->actingAs($user)->get('/connected-members?search=maria')
            ->assertInertia(fn (Assert $page) => $page->where('connections.total', 1)->where('connections.data.0.device_name', 'Maria laptop'));

        $this->actingAs($user)->get('/connected-members?search=unknown-host')
            ->assertInertia(fn (Assert $page) => $page->where('connections.total', 1)->where('connections.data.0.identified', false));
    }

    public function test_invalid_filters_are_rejected_and_search_is_never_sql(): void
    {
        $user = $this->userWithRole('network_admin');

        $this->actingAs($user)->get('/connected-members?status=banana')->assertSessionHasErrors('status');
        $this->actingAs($user)->get('/connected-members?type=toaster')->assertSessionHasErrors('type');
        $this->actingAs($user)->get('/connected-members?office=999999')->assertSessionHasErrors('office');
        $this->actingAs($user)->get('/connected-members?search='.str_repeat('a', 101))->assertSessionHasErrors('search');

        $this->actingAs($user)->get('/connected-members?search='.urlencode("' OR 1=1 --"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('connections.total', 0));
    }

    public function test_an_empty_installation_renders_without_errors(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->get('/connected-members')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('connections.total', 0)
                ->where('source.enabled', false)
                ->where('summary.online_identified', 0));
    }

    public function test_viewing_the_list_is_audited(): void
    {
        $this->actingAs($this->userWithRole('network_admin'))->get('/connected-members?search=Maria')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'office_network.members_viewed']);
    }

    // ---------------------------------------------------------------- export

    public function test_the_export_is_a_csv_with_formula_cells_neutralised_and_it_is_audited(): void
    {
        $this->enableDhcpSource();
        $device = $this->makeDevice($this->makeMember($this->makeOffice('CIDS Office'), '=SUM(A1)'), 'Maria laptop');
        $this->connect($device);

        $response = $this->actingAs($this->userWithRole('network_admin'))->get('/connected-members/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Member,Office,Device', $csv);
        $this->assertStringContainsString('CIDS Office', $csv);
        $this->assertStringContainsString("'=SUM(A1)", $csv);
        $this->assertStringContainsString($device->mac_address, $csv);

        $this->assertDatabaseHas('audit_logs', ['action' => 'office_network.connections_exported']);
    }

    public function test_the_export_follows_the_filters(): void
    {
        $this->enableDhcpSource();
        $this->connect($this->makeDevice($this->makeMember($this->makeOffice('CIDS Office'), 'Maria Santos')));
        $this->connect(null, ['hostname' => 'mystery-host']);

        $csv = $this->actingAs($this->userWithRole('network_admin'))
            ->get('/connected-members/export?status=unidentified')
            ->streamedContent();

        $this->assertStringContainsString('Unidentified device', $csv);
        $this->assertStringNotContainsString('CIDS Office', $csv);
        $this->assertStringNotContainsString('Maria Santos', $csv);
    }

    public function test_an_invalid_export_filter_is_rejected(): void
    {
        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/connected-members/export?status=banana')
            ->assertSessionHasErrors('status');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'office_network.connections_exported']);
    }
}