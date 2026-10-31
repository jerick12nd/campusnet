<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\MonitoredDevice;
use App\Models\NetworkAlert;
use App\Models\NetworkInterface;
use App\Models\TrafficSample;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard numbers come from stored data only. These tests build known data and
 * check the exact figures, the honesty rules (source, demo, stale) and the empty states.
 */
class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);
        $this->now = CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC');
    }

    private function build(string $range = '24h'): array
    {
        return app(DashboardService::class)->build($range, $this->now);
    }

    /** An enabled online device with one WAN interface. */
    private function wan(array $device = []): NetworkInterface
    {
        $model = MonitoredDevice::factory()->online()->create($device);

        return NetworkInterface::factory()->wan()->create(['monitored_device_id' => $model->id]);
    }

    private function sample(NetworkInterface $interface, string $at, float $in, float $out, string $source = 'mock', string $status = 'ok'): TrafficSample
    {
        return TrafficSample::factory()
            ->source($source)
            ->at(CarbonImmutable::parse($at, 'UTC'))
            ->rate($in, $out)
            ->create([
                'network_interface_id' => $interface->id,
                'status' => $status,
                'is_successful' => $status === 'ok',
            ]);
    }

    public function test_with_no_devices_everything_is_empty_and_says_so(): void
    {
        $data = $this->build();

        $this->assertSame('no_devices', $data['collector']['status']);
        $this->assertFalse($data['wan']['configured']);
        $this->assertNull($data['wan']['current']);
        $this->assertSame([], $data['history']);
        $this->assertNull($data['summary']);
        $this->assertSame([], $data['top_interfaces']);
        $this->assertSame([], $data['incidents']);
        $this->assertSame(['total' => 0, 'online' => 0, 'unreachable' => 0, 'unknown' => 0, 'disabled' => 0], $data['devices']);
        $this->assertSame(['active' => 0, 'critical' => 0], $data['alerts']);
        $this->assertNull($data['latest_measurement_at']);
        $this->assertSame(['demo' => true, 'driver' => 'mock'], $data['mode']);
    }

    public function test_a_device_without_measurements_reports_no_data(): void
    {
        MonitoredDevice::factory()->create();

        $this->assertSame('no_data', $this->build()['collector']['status']);
    }

    public function test_capacity_and_thresholds_come_from_the_settings_defaults(): void
    {
        $data = $this->build();

        $this->assertSame(100.0, $data['capacity_mbps']);
        $this->assertSame(['warning' => 80.0, 'critical' => 90.0], $data['thresholds']);
        $this->assertSame(30, $data['refresh_seconds']);
    }

    public function test_current_values_and_utilization_use_the_busiest_direction(): void
    {
        $wan = $this->wan();
        $this->sample($wan, '2026-10-30 11:59:00', 40, 10);

        $current = $this->build()['wan']['current'];

        $this->assertEqualsWithDelta(40.0, $current['in_mbps'], 0.001);
        $this->assertEqualsWithDelta(10.0, $current['out_mbps'], 0.001);
        $this->assertEqualsWithDelta(40.0, $current['utilization_percent'], 0.001, 'Capacity is 100 Mbps, busiest direction is 40 Mbps.');
        $this->assertFalse($current['stale']);
        $this->assertSame(1, $current['interfaces_reporting']);
        $this->assertSame(1, $current['interfaces_total']);

        $this->sample($wan, '2026-10-30 11:59:30', 5, 70);
        $this->assertEqualsWithDelta(70.0, $this->build()['wan']['current']['utilization_percent'], 0.001);
    }

    public function test_utilization_is_not_clamped_when_traffic_exceeds_the_configured_capacity(): void
    {
        $wan = $this->wan();
        $this->sample($wan, '2026-10-30 11:59:00', 130, 20);

        $this->assertEqualsWithDelta(130.0, $this->build()['wan']['current']['utilization_percent'], 0.001);
    }

    public function test_several_wan_links_are_summed(): void
    {
        $first = $this->wan();
        $second = $this->wan();
        $this->sample($first, '2026-10-30 11:59:00', 20, 5);
        $this->sample($second, '2026-10-30 11:59:10', 30, 8);

        $data = $this->build();

        $this->assertEqualsWithDelta(50.0, $data['wan']['current']['in_mbps'], 0.001);
        $this->assertEqualsWithDelta(13.0, $data['wan']['current']['out_mbps'], 0.001);
        $this->assertSame(2, $data['wan']['current']['interfaces_reporting']);
    }

    public function test_old_data_is_flagged_stale_and_the_collector_banner_says_so(): void
    {
        $wan = $this->wan();
        $this->sample($wan, '2026-10-30 11:50:00', 40, 10); // 10 minutes old, window is 5

        $data = $this->build();

        $this->assertTrue($data['wan']['current']['stale']);
        $this->assertSame('stale', $data['collector']['status']);
        $this->assertNotNull($data['collector']['message']);
    }

    public function test_fresh_data_is_ok(): void
    {
        $wan = $this->wan();
        $this->sample($wan, '2026-10-30 11:58:00', 40, 10);

        $data = $this->build();

        $this->assertSame('ok', $data['collector']['status']);
        $this->assertNull($data['collector']['message']);
        $this->assertSame('2026-10-30T11:58:00+00:00', $data['latest_measurement_at']);
    }

    public function test_samples_without_a_valid_rate_are_ignored(): void
    {
        $wan = $this->wan();
        $this->sample($wan, '2026-10-30 11:50:00', 40, 10);
        $this->sample($wan, '2026-10-30 11:55:00', 999, 999, 'mock', 'counter_reset');
        $this->sample($wan, '2026-10-30 11:56:00', 0, 0, 'mock', 'failed');

        $data = $this->build('1h');

        $this->assertEqualsWithDelta(40.0, $data['wan']['current']['in_mbps'], 0.001);
        $this->assertCount(1, $data['history']);
        $this->assertEqualsWithDelta(40.0, $data['history'][0]['peak_in_mbps'], 0.001);
    }

    public function test_a_real_driver_never_shows_mock_samples_or_demo_devices(): void
    {
        config(['campusnet.driver' => 'snmp']);

        $demo = $this->wan(['is_demo' => true]);
        $this->sample($demo, '2026-10-30 11:59:00', 80, 20, 'mock');

        $data = $this->build();

        $this->assertSame(['demo' => false, 'driver' => 'snmp'], $data['mode']);
        $this->assertSame('no_devices', $data['collector']['status']);
        $this->assertFalse($data['wan']['configured']);
        $this->assertNull($data['wan']['current']);
        $this->assertSame([], $data['history']);
        $this->assertSame(0, $data['devices']['total']);
        $this->assertNull($data['latest_measurement_at']);
    }

    public function test_a_real_driver_ignores_mock_samples_even_on_a_real_device(): void
    {
        config(['campusnet.driver' => 'snmp']);

        $wan = $this->wan();
        $this->sample($wan, '2026-10-30 11:59:00', 80, 20, 'mock');

        $data = $this->build();

        $this->assertTrue($data['wan']['configured']);
        $this->assertNull($data['wan']['current'], 'Simulated samples must not appear while a real driver is active.');
        $this->assertSame('no_data', $data['collector']['status']);
    }

    public function test_the_mock_driver_ignores_real_samples(): void
    {
        $wan = $this->wan();
        $this->sample($wan, '2026-10-30 11:59:00', 80, 20, 'snmp');

        $this->assertNull($this->build()['wan']['current']);
    }

    public function test_device_counts_cover_status_disabled_and_archived(): void
    {
        MonitoredDevice::factory()->online()->count(2)->create();
        MonitoredDevice::factory()->unreachable()->create();
        MonitoredDevice::factory()->create(); // never polled: unknown
        MonitoredDevice::factory()->online()->disabled()->create();
        MonitoredDevice::factory()->online()->create()->delete();

        $this->assertSame(
            ['total' => 4, 'online' => 2, 'unreachable' => 1, 'unknown' => 1, 'disabled' => 1],
            $this->build()['devices'],
        );
    }

    public function test_alert_counts_include_acknowledged_but_not_resolved(): void
    {
        NetworkAlert::factory()->critical()->create();
        NetworkAlert::factory()->create();
        NetworkAlert::factory()->critical()->acknowledged()->create();
        NetworkAlert::factory()->critical()->resolved()->create();

        $this->assertSame(['active' => 3, 'critical' => 2], $this->build()['alerts']);
    }

    public function test_demo_alerts_are_hidden_when_a_real_driver_is_active(): void
    {
        $demo = MonitoredDevice::factory()->demo()->create();
        NetworkAlert::factory()->critical()->create(['monitored_device_id' => $demo->id]);

        config(['campusnet.driver' => 'snmp']);
        $this->assertSame(['active' => 0, 'critical' => 0], $this->build()['alerts']);
        $this->assertSame([], $this->build()['incidents']);

        config(['campusnet.driver' => 'mock']);
        $this->assertSame(['active' => 1, 'critical' => 1], $this->build()['alerts']);
        $this->assertCount(1, $this->build()['incidents']);
    }

    public function test_history_averages_samples_inside_a_bucket_and_keeps_the_peak(): void
    {
        $wan = $this->wan();
        // Last-hour range uses 60-second buckets: these two share the 11:30 bucket.
        $this->sample($wan, '2026-10-30 11:30:10', 10, 4);
        $this->sample($wan, '2026-10-30 11:30:40', 30, 6);
        $this->sample($wan, '2026-10-30 11:31:05', 50, 8);

        $data = $this->build('1h');

        $this->assertCount(2, $data['history']);

        $first = $data['history'][0];
        $this->assertSame('2026-10-30T11:30:00+00:00', $first['t']);
        $this->assertEqualsWithDelta(20.0, $first['in_mbps'], 0.001);
        $this->assertEqualsWithDelta(30.0, $first['peak_in_mbps'], 0.001);
        $this->assertEqualsWithDelta(20.0, $first['utilization_percent'], 0.001);
        $this->assertEqualsWithDelta(30.0, $first['peak_utilization_percent'], 0.001);

        $this->assertSame('2026-10-30T11:31:00+00:00', $data['history'][1]['t']);
        $this->assertEqualsWithDelta(50.0, $data['history'][1]['utilization_percent'], 0.001);

        $this->assertEqualsWithDelta(50.0, $data['summary']['peak_in_mbps'], 0.001);
        $this->assertEqualsWithDelta(50.0, $data['summary']['peak_utilization_percent'], 0.001);
        $this->assertEqualsWithDelta(35.0, $data['summary']['avg_utilization_percent'], 0.001);
    }

    public function test_the_selected_range_limits_the_history(): void
    {
        $wan = $this->wan();
        $this->sample($wan, '2026-10-30 11:30:00', 10, 1);   // 30 minutes ago
        $this->sample($wan, '2026-10-30 05:00:00', 20, 2);   // 7 hours ago (a different 6-hour chart bucket from the sample above)
        $this->sample($wan, '2026-10-27 12:00:00', 30, 3);   // 3 days ago
        $this->sample($wan, '2026-10-10 12:00:00', 40, 4);   // 20 days ago

        $this->assertCount(1, $this->build('1h')['history']);
        $this->assertCount(2, $this->build('24h')['history']);
        $this->assertCount(3, $this->build('7d')['history']);
        $this->assertCount(4, $this->build('30d')['history']);
    }

    public function test_an_unknown_range_falls_back_to_the_default(): void
    {
        $this->assertSame('24h', $this->build('forever')['range']);
        $this->assertSame(['1h', '24h', '7d', '30d'], $this->build()['ranges']);
    }

    public function test_top_interfaces_are_ranked_by_average_traffic(): void
    {
        $device = MonitoredDevice::factory()->online()->create();
        $quiet = NetworkInterface::factory()->lan()->create(['monitored_device_id' => $device->id, 'name' => 'quiet']);
        $busy = NetworkInterface::factory()->lan()->create(['monitored_device_id' => $device->id, 'name' => 'busy']);

        $this->sample($quiet, '2026-10-30 11:00:00', 2, 1);
        $this->sample($busy, '2026-10-30 11:00:00', 30, 10);
        $this->sample($busy, '2026-10-30 11:05:00', 10, 10);

        $top = $this->build('24h')['top_interfaces'];

        $this->assertSame(['busy', 'quiet'], array_column($top, 'name'));
        $this->assertEqualsWithDelta(30.0, $top[0]['avg_total_mbps'], 0.001);
        $this->assertSame($device->name, $top[0]['device']);
    }

    public function test_incidents_are_listed_newest_first_and_limited_to_eight(): void
    {
        $device = MonitoredDevice::factory()->create();

        foreach (range(1, 10) as $i) {
            NetworkAlert::factory()->create([
                'monitored_device_id' => $device->id,
                'title' => "Incident {$i}",
                'triggered_at' => $this->now->subMinutes(100 - $i),
            ]);
        }

        $incidents = $this->build()['incidents'];

        $this->assertCount(8, $incidents);
        $this->assertSame('Incident 10', $incidents[0]['title']);
        $this->assertSame($device->name, $incidents[0]['device']);
    }

    public function test_an_unknown_driver_is_reported_as_misconfigured(): void
    {
        config(['campusnet.driver' => 'carrier-pigeon']);
        MonitoredDevice::factory()->create();

        $this->assertSame('misconfigured', $this->build()['collector']['status']);
    }
}