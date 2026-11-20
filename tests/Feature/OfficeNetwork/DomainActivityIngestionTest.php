<?php

declare(strict_types=1);

namespace Tests\Feature\OfficeNetwork;

use App\Enums\ObservationType;
use App\Models\ActivityDataSource;
use App\Models\DeviceConnectionSession;
use App\Models\DomainActivityRecord;
use App\Models\RegisteredDevice;
use App\Services\DomainActivityIngestor;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainActivityIngestionTest extends TestCase
{
    use BuildsDomainActivity;
    use BuildsOfficeNetwork;
    use RefreshDatabase;

    private CarbonImmutable $base;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);

        // Start of the previous hour: always in the past and aligned to the five-minute buckets.
        $this->base = CarbonImmutable::now()->startOfHour()->subHour();
    }

    /**
     * @param  list<array<string, mixed>>  $observations
     * @return array<string, int>
     */
    private function ingest(ActivityDataSource $source, array $observations): array
    {
        return app(DomainActivityIngestor::class)->ingest($source, $observations);
    }

    /** @param array<string, mixed> $extra */
    private function seen(string $domain, int $seconds = 10, string $ip = '192.168.10.50', array $extra = []): array
    {
        return ['client_ip' => $ip, 'domain' => $domain, 'observed_at' => $this->base->addSeconds($seconds), ...$extra];
    }

    /**
     * A connection that was open around the test time. Not called session(): Laravel's TestCase already has one.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function openSession(?RegisteredDevice $device, string $ip = '192.168.10.50', array $attributes = []): DeviceConnectionSession
    {
        return $this->connect($device, [
            'ip_address' => $ip,
            'started_at' => $this->base->subMinutes(10),
            'last_seen_at' => $this->base->addMinutes(30),
            ...$attributes,
        ]);
    }

    public function test_lookups_from_a_registered_device_are_attributed_and_aggregated_per_bucket(): void
    {
        $office = $this->makeOffice('CIDS Office');
        $member = $this->makeMember($office, 'Maria Santos');
        $device = $this->makeDevice($member, 'Maria laptop');
        $this->openSession($device);
        $source = $this->makeSource('dns');

        $stats = $this->ingest($source, [
            $this->seen('www.google.com', 10),
            $this->seen('www.google.com', 20),
            $this->seen('WWW.Google.com.', 30),
            $this->seen('youtube.com', 40),
        ]);

        $this->assertEquals(['received' => 4, 'invalid' => 0, 'stored' => 2, 'attributed' => 4, 'unidentified' => 0], $stats);
        $this->assertSame(2, DomainActivityRecord::count());

        $google = DomainActivityRecord::query()->where('domain', 'www.google.com')->firstOrFail();

        $this->assertSame(3, $google->hit_count);
        $this->assertSame('google.com', $google->base_domain);
        $this->assertSame($device->id, $google->registered_device_id);
        $this->assertSame($member->id, $google->member_id);
        $this->assertSame($office->id, $google->office_id);
        $this->assertSame($source->id, $google->activity_data_source_id);
        $this->assertSame(ObservationType::DnsQuery, $google->observation_type);
        $this->assertSame('192.168.10.50', $google->client_ip);
        $this->assertSame($this->base->getTimestamp(), $google->observed_at->getTimestamp());
        $this->assertNull($google->traffic_bytes);
    }

    public function test_an_unknown_ip_is_stored_as_unidentified(): void
    {
        $this->makeMember($this->makeOffice(), 'Maria Santos');

        $stats = $this->ingest($this->makeSource('dns'), [$this->seen('www.google.com')]);

        $this->assertEquals(1, $stats['unidentified']);
        $this->assertEquals(0, $stats['attributed']);

        $record = DomainActivityRecord::firstOrFail();

        $this->assertNull($record->registered_device_id);
        $this->assertNull($record->member_id);
        $this->assertNull($record->office_id);
    }

    public function test_a_connection_that_is_not_registered_is_never_attributed_to_a_member(): void
    {
        $this->makeMember($this->makeOffice(), 'Maria Santos');
        $this->openSession(null);

        $stats = $this->ingest($this->makeSource('dns'), [$this->seen('www.google.com')]);

        $this->assertEquals(1, $stats['unidentified']);
        $this->assertNull(DomainActivityRecord::firstOrFail()->member_id);
    }

    public function test_an_open_session_that_stopped_being_seen_does_not_claim_new_traffic(): void
    {
        $device = $this->makeDevice($this->makeMember($this->makeOffice()));
        $this->connect($device, [
            'ip_address' => '192.168.10.50',
            'started_at' => $this->base->subHours(2),
            'last_seen_at' => $this->base->subHour(),
        ]);

        $stats = $this->ingest($this->makeSource('dns'), [$this->seen('www.google.com')]);

        $this->assertEquals(1, $stats['unidentified']);
        $this->assertNull(DomainActivityRecord::firstOrFail()->registered_device_id);
    }

    public function test_a_closed_session_only_covers_the_time_it_was_open(): void
    {
        $device = $this->makeDevice($this->makeMember($this->makeOffice()));
        $this->openSession($device, attributes: ['ended_at' => $this->base->addMinutes(5), 'last_seen_at' => $this->base->addMinutes(5)]);

        $stats = $this->ingest($this->makeSource('dns'), [
            $this->seen('before.example.com', 60),
            $this->seen('after.example.com', 600),
        ]);

        $this->assertEquals(1, $stats['attributed']);
        $this->assertEquals(1, $stats['unidentified']);
        $this->assertSame($device->id, DomainActivityRecord::query()->where('domain', 'before.example.com')->value('registered_device_id'));
        $this->assertNull(DomainActivityRecord::query()->where('domain', 'after.example.com')->value('registered_device_id'));
    }

    public function test_a_reused_ip_goes_to_the_member_who_had_it_at_that_time(): void
    {
        $office = $this->makeOffice();
        $maria = $this->makeMember($office, 'Maria Santos');
        $ana = $this->makeMember($office, 'Ana Cruz');
        $mariaDevice = $this->makeDevice($maria);
        $anaDevice = $this->makeDevice($ana);

        $this->connect($mariaDevice, [
            'ip_address' => '192.168.10.50',
            'started_at' => $this->base->subHours(3),
            'ended_at' => $this->base->subMinutes(30),
            'last_seen_at' => $this->base->subMinutes(30),
        ]);
        $this->openSession($anaDevice, attributes: ['started_at' => $this->base->subMinutes(20)]);

        $this->ingest($this->makeSource('dns'), [
            $this->seen('early.example.com', -2700),
            $this->seen('late.example.com', 60),
        ]);

        $this->assertSame($maria->id, DomainActivityRecord::query()->where('domain', 'early.example.com')->value('member_id'));
        $this->assertSame($ana->id, DomainActivityRecord::query()->where('domain', 'late.example.com')->value('member_id'));
    }

    public function test_the_mac_address_decides_when_two_sessions_share_an_ip(): void
    {
        $office = $this->makeOffice();
        $first = $this->makeDevice($this->makeMember($office, 'First'));
        $second = $this->makeDevice($this->makeMember($office, 'Second'));

        $this->openSession($first, attributes: ['started_at' => $this->base->subMinutes(5)]);
        $this->openSession($second, attributes: ['started_at' => $this->base->subMinutes(10)]);

        $this->ingest($this->makeSource('dns'), [$this->seen('www.google.com', 10, extra: ['mac' => $second->mac_address])]);

        $this->assertSame($second->id, DomainActivityRecord::firstOrFail()->registered_device_id);
    }

    public function test_ingesting_the_same_bucket_twice_merges_into_one_record(): void
    {
        $source = $this->makeSource('dns');

        $this->ingest($source, [$this->seen('www.google.com', 10), $this->seen('www.google.com', 20)]);
        $this->ingest($source, [$this->seen('www.google.com', 30)]);

        $this->assertSame(1, DomainActivityRecord::count());
        $this->assertSame(3, DomainActivityRecord::firstOrFail()->hit_count);
    }

    public function test_traffic_is_only_stored_when_the_source_measured_it(): void
    {
        $source = $this->makeSource('proxy');

        $this->ingest($source, [
            $this->seen('www.google.com', 10, extra: ['traffic_bytes' => 100]),
            $this->seen('www.google.com', 20, extra: ['traffic_bytes' => 50]),
            $this->seen('plain.example.com', 30),
        ]);

        $this->assertSame(150, (int) DomainActivityRecord::query()->where('domain', 'www.google.com')->value('traffic_bytes'));
        $this->assertNull(DomainActivityRecord::query()->where('domain', 'plain.example.com')->value('traffic_bytes'));
    }

    public function test_the_observation_type_follows_the_source_type(): void
    {
        $this->ingest($this->makeSource('proxy'), [$this->seen('www.google.com')]);

        $this->assertSame(ObservationType::ProxyRequest, DomainActivityRecord::firstOrFail()->observation_type);
    }

    public function test_unusable_observations_are_counted_and_skipped(): void
    {
        $ip = '192.168.10.50';

        $stats = $this->ingest($this->makeSource('dns'), [
            ['client_ip' => $ip, 'domain' => 'router1', 'observed_at' => $this->base],
            ['client_ip' => 'not-an-ip', 'domain' => 'google.com', 'observed_at' => $this->base],
            ['client_ip' => $ip, 'domain' => 'printer.local', 'observed_at' => $this->base],
            ['client_ip' => $ip, 'domain' => 'google.com'],
            ['client_ip' => $ip, 'domain' => 'google.com', 'observed_at' => 'garbage'],
            ['client_ip' => $ip, 'domain' => 'google.com', 'observed_at' => now()->addDay()],
        ]);

        $this->assertEquals(['received' => 6, 'invalid' => 6, 'stored' => 0, 'attributed' => 0, 'unidentified' => 0], $stats);
        $this->assertSame(0, DomainActivityRecord::count());
    }

    public function test_a_disabled_source_cannot_ingest(): void
    {
        $this->expectException(DomainException::class);

        $this->ingest($this->makeSource('dns', ['is_enabled' => false]), [$this->seen('www.google.com')]);
    }

    public function test_a_source_that_does_not_see_domains_cannot_ingest(): void
    {
        $this->expectException(DomainException::class);

        $this->ingest($this->makeSource('dhcp'), [$this->seen('www.google.com')]);
    }
}