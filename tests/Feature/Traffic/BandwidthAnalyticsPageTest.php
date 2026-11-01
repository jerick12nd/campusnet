<?php

declare(strict_types=1);

namespace Tests\Feature\Traffic;

use App\Enums\InterfaceRole;
use App\Models\MonitoredDevice;
use App\Models\NetworkInterface;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BandwidthAnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/bandwidth-analytics')->assertRedirect();
    }

    public function test_without_a_wan_interface_it_says_so_instead_of_inventing_numbers(): void
    {
        $this->actingAs($this->viewer())
            ->get('/bandwidth-analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bandwidth-analytics')
                ->where('analytics.wan.configured', false)
                ->where('analytics.has_data', false)
                ->where('analytics.summary', null));
    }

    public function test_a_wan_interface_without_samples_reports_no_data(): void
    {
        $this->wan();

        $this->actingAs($this->viewer())
            ->get('/bandwidth-analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('analytics.wan.configured', true)
                ->where('analytics.has_data', false));
    }

    public function test_the_summary_is_computed_from_valid_stored_samples_only(): void
    {
        [$device, $wan] = $this->wan();
        $now = CarbonImmutable::now('UTC');

        // Four valid samples of 60 s each: 10 %, 20 %, 30 % and 90 % utilization, 8 Mbps inbound.
        foreach ([10.0, 20.0, 30.0, 90.0] as $index => $utilization) {
            $this->sample($device, $wan, $now->subHours(5 - $index), 'ok', 8.0, 0.0, $utilization);
        }

        // These must not count: a failed poll and a sample of another source.
        $this->sample($device, $wan, $now->subHours(1), 'failed', null, null, null);
        $this->sample($device, $wan, $now->subHours(2), 'ok', 50.0, 50.0, 99.0, source: 'snmp');

        $this->actingAs($this->viewer())
            ->get('/bandwidth-analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('analytics.has_data', true)
                ->where('analytics.summary.sample_count', 4)
                ->where('analytics.summary.avg_utilization_percent', fn ($value) => abs($value - 37.5) < 0.01)
                ->where('analytics.summary.p95_utilization_percent', fn ($value) => abs($value - 90.0) < 0.01)
                ->where('analytics.summary.peak_utilization_percent', fn ($value) => abs($value - 90.0) < 0.01)
                // 90 % is the only sample at or above the 80 % warning threshold: 60 s of 240 s.
                ->where('analytics.summary.above_warning_percent', fn ($value) => abs($value - 25.0) < 0.01)
                // 8 Mbps x 60 s x 4 samples = 1920 megabit = 240 megabyte.
                ->where('analytics.summary.transferred.in_mb', fn ($value) => abs($value - 240.0) < 0.01)
                ->has('analytics.hourly', 24)
                ->has('analytics.weekday', 7));
    }

    public function test_an_unknown_range_is_rejected(): void
    {
        $this->actingAs($this->viewer())
            ->get('/bandwidth-analytics?range=1y')
            ->assertSessionHasErrors('range');
    }

    private function viewer(): User
    {
        return User::factory()->create(['role' => 'viewer', 'is_active' => true]);
    }

    /**
     * @return array{0: MonitoredDevice, 1: NetworkInterface}
     */
    private function wan(): array
    {
        $device = new MonitoredDevice(['name' => 'Core router', 'management_ip' => '192.0.2.1']);
        $device->save();

        $wan = NetworkInterface::create([
            'monitored_device_id' => $device->id,
            'if_index' => 1,
            'name' => 'ether1-WAN',
            'role' => InterfaceRole::Wan,
            'is_monitored' => true,
            'speed_mbps' => 100,
        ]);

        return [$device, $wan];
    }

    private function sample(
        MonitoredDevice $device,
        NetworkInterface $interface,
        CarbonImmutable $at,
        string $status,
        ?float $in,
        ?float $out,
        ?float $utilization,
        string $source = 'mock',
    ): void {
        DB::table('traffic_samples')->insert([
            'monitored_device_id' => $device->id,
            'network_interface_id' => $interface->id,
            'sampled_at' => $at->format('Y-m-d H:i:s'),
            'in_mbps' => $in,
            'out_mbps' => $out,
            'utilization_percent' => $utilization,
            'elapsed_seconds' => $status === 'ok' ? 60 : null,
            'source' => $source,
            'status' => $status,
            'is_successful' => $status === 'ok',
            'created_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }
}