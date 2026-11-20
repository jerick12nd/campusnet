<?php

declare(strict_types=1);

namespace Tests\Feature\OfficeNetwork;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebsiteActivityPageTest extends TestCase
{
    use BuildsDomainActivity;
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
        $this->get('/website-activity')->assertRedirect();
        $this->get('/website-activity/export')->assertRedirect();
        $this->get('/activity-sources')->assertRedirect();
    }

    public function test_a_viewer_cannot_open_or_export_website_activity(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)->get('/website-activity')->assertForbidden();
        $this->actingAs($viewer)->get('/website-activity/export')->assertForbidden();
    }

    public function test_an_ict_administrator_sees_domains_and_offices_but_never_who(): void
    {
        $source = $this->makeSource('dns');
        $office = $this->makeOffice('CIDS Office');
        $member = $this->makeMember($office, 'Maria Santos');
        $device = $this->makeDevice($member, 'Maria laptop');
        $this->makeRecord($source, ['registered_device_id' => $device->id, 'member_id' => $member->id, 'office_id' => $office->id]);

        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/website-activity')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('website-activity/index')
                ->where('can.memberLevel', false)
                ->where('members', [])
                ->has('records.data', 1)
                ->where('records.data.0.domain', 'www.google.com')
                ->where('records.data.0.office_name', 'CIDS Office')
                ->missing('records.data.0.member_name')
                ->missing('records.data.0.device_name')
                ->missing('records.data.0.client_ip'))
            ->assertDontSee('Maria Santos')
            ->assertDontSee('Maria laptop')
            ->assertDontSee('192.168.10.50');
    }

    public function test_member_filters_are_refused_for_anyone_but_a_super_administrator(): void
    {
        $member = $this->makeMember($this->makeOffice());
        $admin = $this->userWithRole('network_admin');

        $this->actingAs($admin)->get("/website-activity?member={$member->id}")->assertForbidden();
        $this->actingAs($admin)->get("/website-activity/export?member={$member->id}")->assertForbidden();
    }

    public function test_a_super_administrator_sees_who_and_can_filter_by_member(): void
    {
        $source = $this->makeSource('dns');
        $office = $this->makeOffice('CIDS Office');
        $maria = $this->makeMember($office, 'Maria Santos');
        $pedro = $this->makeMember($office, 'Pedro Reyes');
        $device = $this->makeDevice($maria, 'Maria laptop');

        $this->makeRecord($source, ['registered_device_id' => $device->id, 'member_id' => $maria->id, 'office_id' => $office->id]);
        $this->makeRecord($source, ['domain' => 'youtube.com', 'base_domain' => 'youtube.com', 'member_id' => $pedro->id, 'office_id' => $office->id]);

        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)->get('/website-activity')
            ->assertInertia(fn (Assert $page) => $page->where('can.memberLevel', true)->has('members', 2)->has('records.data', 2));

        $this->actingAs($admin)->get("/website-activity?member={$maria->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.member_name', 'Maria Santos')
                ->where('records.data.0.device_name', 'Maria laptop')
                ->where('records.data.0.client_ip', '192.168.10.50'));

        $this->actingAs($admin)->get('/website-activity?member=999999')->assertSessionHasErrors('member');
    }

    // ------------------------------------------------------------- summaries

    public function test_the_summaries_are_computed_from_the_stored_records(): void
    {
        $source = $this->makeSource('dns');
        $cids = $this->makeOffice('CIDS Office');
        $registrar = $this->makeOffice('Registrar');

        $this->makeRecord($source, ['office_id' => $cids->id, 'hit_count' => 5]);
        $this->makeRecord($source, ['domain' => 'mail.google.com', 'office_id' => $cids->id, 'hit_count' => 3]);
        $this->makeRecord($source, ['domain' => 'youtube.com', 'base_domain' => 'youtube.com', 'office_id' => $registrar->id, 'hit_count' => 4]);
        $this->makeRecord($source, ['domain' => 'facebook.com', 'base_domain' => 'facebook.com', 'hit_count' => 1]);

        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/website-activity')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.hits', 13)
                ->where('summary.domains', 3)
                ->where('summary.records', 4)
                ->where('topDomains.0.domain', 'google.com')
                ->where('topDomains.0.hits', 8)
                ->where('topDomains.1.domain', 'youtube.com')
                ->where('topDomains.2.domain', 'facebook.com')
                ->where('byOffice.0.name', 'CIDS Office')
                ->where('byOffice.0.hits', 8)
                ->where('byOffice.1.name', 'Registrar')
                ->where('byOffice.2.name', 'Unidentified devices')
                ->has('trend', 7)
                ->where('trend', fn ($trend) => collect($trend)->sum('hits') === 13));
    }

    public function test_each_record_says_what_kind_of_observation_it_is(): void
    {
        $source = $this->makeSource('dns');
        $this->makeRecord($source, ['domain' => 'dns.example.com', 'observed_at' => now()->subHours(2)]);
        $this->makeRecord($source, ['domain' => 'proxy.example.com', 'observation_type' => 'proxy_request', 'observed_at' => now()->subHour()]);

        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/website-activity')
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.data.0.observation_label', 'Proxy request')
                ->where('records.data.1.observation_label', 'DNS lookup')
                ->where('records.data.0.source_name', 'Source dns'));
    }

    // --------------------------------------------------------------- filters

    public function test_the_default_range_is_the_last_seven_days(): void
    {
        $source = $this->makeSource('dns');
        $this->makeRecord($source, ['domain' => 'recent.example.com']);
        $this->makeRecord($source, ['domain' => 'old.example.com', 'observed_at' => now()->subDays(10)]);

        $admin = $this->userWithRole('network_admin');

        $this->actingAs($admin)->get('/website-activity')
            ->assertInertia(fn (Assert $page) => $page->where('summary.records', 1)->where('records.data.0.domain', 'recent.example.com'));

        $from = now()->subDays(12)->format('Y-m-d');
        $to = now()->addDay()->format('Y-m-d');

        $this->actingAs($admin)->get("/website-activity?from={$from}&to={$to}")
            ->assertInertia(fn (Assert $page) => $page->where('summary.records', 2));
    }

    public function test_dates_are_validated_and_the_range_is_limited(): void
    {
        $admin = $this->userWithRole('network_admin');

        $this->actingAs($admin)->get('/website-activity?from=2026-13-45')->assertSessionHasErrors('from');
        $this->actingAs($admin)->get('/website-activity?from=2026-05-10&to=2026-05-01')->assertSessionHasErrors('to');
        $this->actingAs($admin)->get('/website-activity?from=2026-01-01&to=2026-06-01')->assertSessionHasErrors('to');
        $this->actingAs($admin)->get('/website-activity?from=2026-05-01&to=2026-05-20')->assertSessionHasNoErrors();
    }

    public function test_activity_can_be_searched_and_filtered(): void
    {
        $source = $this->makeSource('dns');
        $cids = $this->makeOffice('CIDS Office');
        $registrar = $this->makeOffice('Registrar');

        $this->makeRecord($source, ['office_id' => $cids->id]);
        $this->makeRecord($source, ['domain' => 'youtube.com', 'base_domain' => 'youtube.com', 'office_id' => $registrar->id, 'observation_type' => 'proxy_request']);

        $admin = $this->userWithRole('network_admin');

        $this->actingAs($admin)->get('/website-activity?search=youtube')
            ->assertInertia(fn (Assert $page) => $page->where('summary.records', 1)->where('records.data.0.domain', 'youtube.com'));

        $this->actingAs($admin)->get("/website-activity?office={$cids->id}")
            ->assertInertia(fn (Assert $page) => $page->where('summary.records', 1)->where('records.data.0.domain', 'www.google.com'));

        $this->actingAs($admin)->get('/website-activity?type=proxy_request')
            ->assertInertia(fn (Assert $page) => $page->where('summary.records', 1)->where('records.data.0.domain', 'youtube.com'));
    }

    public function test_invalid_filters_are_rejected_and_search_is_never_sql(): void
    {
        $admin = $this->userWithRole('network_admin');

        $this->actingAs($admin)->get('/website-activity?type=banana')->assertSessionHasErrors('type');
        $this->actingAs($admin)->get('/website-activity?office=999999')->assertSessionHasErrors('office');
        $this->actingAs($admin)->get('/website-activity?search='.str_repeat('a', 101))->assertSessionHasErrors('search');

        $this->actingAs($admin)->get('/website-activity?search='.urlencode("' OR 1=1 --"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.records', 0));
    }

    // ---------------------------------------------------------- empty states

    public function test_without_any_source_the_page_explains_what_is_needed_instead_of_inventing_data(): void
    {
        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/website-activity')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sources', 0)
                ->where('summary.hits', 0)
                ->has('records.data', 0));
    }

    public function test_an_enabled_source_without_records_reports_no_activity(): void
    {
        $this->makeSource('dns');

        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/website-activity')
            ->assertInertia(fn (Assert $page) => $page
                ->where('sources.0.is_enabled', true)
                ->where('sources.0.type_label', 'DNS lookup')
                ->where('summary.hits', 0));
    }

    // ----------------------------------------------------------------- audit

    public function test_viewing_website_activity_is_audited(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))->get('/website-activity?search=google')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'office_network.website_activity_viewed']);
    }

    // ---------------------------------------------------------------- export

    public function test_the_export_for_an_ict_administrator_has_no_member_columns(): void
    {
        $source = $this->makeSource('dns');
        $office = $this->makeOffice('CIDS Office');
        $member = $this->makeMember($office, 'Maria Santos');
        $this->makeRecord($source, ['member_id' => $member->id, 'office_id' => $office->id]);

        $response = $this->actingAs($this->userWithRole('network_admin'))->get('/website-activity/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Domain,"Base domain"', $csv);
        $this->assertStringContainsString('www.google.com', $csv);
        $this->assertStringContainsString('DNS lookup', $csv);
        $this->assertStringContainsString('CIDS Office', $csv);
        $this->assertStringNotContainsString('Maria Santos', $csv);
        $this->assertStringNotContainsString('IP address', $csv);
        $this->assertStringNotContainsString('192.168.10.50', $csv);

        $this->assertDatabaseHas('audit_logs', ['action' => 'office_network.website_activity_exported']);
    }

    public function test_the_export_for_a_super_administrator_includes_who(): void
    {
        $source = $this->makeSource('dns');
        $office = $this->makeOffice('CIDS Office');
        $member = $this->makeMember($office, '=SUM(A1)');
        $this->makeRecord($source, ['member_id' => $member->id, 'office_id' => $office->id]);

        $csv = $this->actingAs($this->userWithRole('super_admin'))->get('/website-activity/export')->streamedContent();

        $this->assertStringContainsString('Member,Device,"IP address"', $csv);
        $this->assertStringContainsString("'=SUM(A1)", $csv); // formula cells are neutralised
        $this->assertStringContainsString('192.168.10.50', $csv);
    }

    public function test_an_invalid_export_filter_is_rejected_and_not_audited(): void
    {
        $this->actingAs($this->userWithRole('network_admin'))
            ->get('/website-activity/export?type=banana')
            ->assertSessionHasErrors('type');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'office_network.website_activity_exported']);
    }
}