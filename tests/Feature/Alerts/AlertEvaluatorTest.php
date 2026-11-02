<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\InterfaceRole;
use App\Models\MonitoredDevice;
use App\Models\NetworkAlert;
use App\Models\NetworkInterface;
use App\Services\AlertEvaluator;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlertEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);

        $this->now = CarbonImmutable::now('UTC');
    }

    public function test_an_unreachable_device_raises_one_alert_and_it_is_not_duplicated(): void
    {
        $device = $this->device(['last_status' => 'unreachable', 'last_successful_poll_at' => $this->now->subMinutes(10)]);
        $this->freshSample($device); // keeps the "no recent measurements" rule out of this test

        $first = $this->evaluate();
        $second = $this->evaluate();

        $this->assertSame(1, $first['opened']);
        $this->assertSame(0, $second['opened']);

        $alerts = NetworkAlert::query()->where('dedup_key', "device:{$device->id}:unreachable")->get();

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::DeviceUnreachable, $alerts[0]->type);
        $this->assertSame(AlertSeverity::Critical, $alerts[0]->severity);
        $this->assertSame(AlertStatus::Active, $alerts[0]->status);
    }

    public function test_one_lost_poll_inside_the_grace_period_is_not_an_alert(): void
    {
        $this->device(['last_status' => 'unreachable', 'last_successful_poll_at' => $this->now->subSeconds(45)]);

        $this->evaluate();

        $this->assertSame(0, NetworkAlert::query()->where('type', AlertType::DeviceUnreachable->value)->count());
    }

    public function test_the_grace_period_grows_with_a_slow_polling_interval(): void
    {
        // Polled every 5 minutes: it may stay silent for 10 minutes before it counts as unreachable.
        $device = $this->device([
            'last_status' => 'unreachable',
            'polling_interval_seconds' => 300,
            'last_successful_poll_at' => $this->now->subSeconds(400),
        ]);

        $this->evaluate();
        $this->assertSame(0, NetworkAlert::query()->where('type', AlertType::DeviceUnreachable->value)->count());

        $device->forceFill(['last_successful_poll_at' => $this->now->subSeconds(700)])->save();

        $this->evaluate();
        $this->assertSame(1, NetworkAlert::query()->where('type', AlertType::DeviceUnreachable->value)->count());
    }

    public function test_a_device_that_never_answered_is_unreachable_straight_away(): void
    {
        $this->device(['last_status' => 'unreachable', 'last_successful_poll_at' => null]);

        $this->evaluate();

        $this->assertSame(1, NetworkAlert::query()->where('type', AlertType::DeviceUnreachable->value)->open()->count());
    }

    public function test_a_recovered_device_resolves_the_alert_and_leaves_an_informational_event(): void
    {
        $device = $this->device(['last_status' => 'unreachable', 'last_successful_poll_at' => $this->now->subMinutes(10)]);
        $this->freshSample($device);

        $this->evaluate();

        $device->forceFill(['last_status' => 'online', 'last_successful_poll_at' => $this->now])->save();

        $result = $this->evaluate();

        $this->assertSame(1, $result['resolved']);

        $alert = NetworkAlert::query()->where('dedup_key', "device:{$device->id}:unreachable")->firstOrFail();

        $this->assertSame(AlertStatus::Resolved, $alert->status);
        $this->assertNull($alert->resolved_by);
        $this->assertNotNull($alert->resolved_at);

        $event = NetworkAlert::query()->where('type', AlertType::DeviceRecovered->value)->firstOrFail();

        $this->assertSame(AlertSeverity::Info, $event->severity);
        $this->assertSame(AlertStatus::Resolved, $event->status);
        $this->assertSame(0, NetworkAlert::query()->open()->count());
    }

    public function test_high_utilization_escalates_an_acknowledged_alert_and_holds_near_the_threshold(): void
    {
        [$warning, $critical] = $this->thresholds();
        [$device, $wan] = $this->deviceWithWan();

        // Warning level.
        $this->samples($device, $wan, $warning + 1.0);
        $this->evaluate();

        $alert = NetworkAlert::query()->where('dedup_key', "interface:{$wan->id}:utilization")->firstOrFail();
        $this->assertSame(AlertType::UtilizationWarning, $alert->type);
        $this->assertSame(AlertSeverity::Warning, $alert->severity);

        // Somebody acknowledges it.
        $alert->forceFill(['status' => AlertStatus::Acknowledged, 'acknowledged_at' => $this->now])->save();

        // Still above the warning threshold: nothing changes, the acknowledgement stays.
        $this->evaluate();
        $this->assertSame(AlertStatus::Acknowledged, $alert->refresh()->status);

        // Critical level: the same alert becomes critical and active again.
        $this->samples($device, $wan, $critical + 1.0);
        $this->evaluate();

        $alert->refresh();
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertSame(AlertType::UtilizationCritical, $alert->type);
        $this->assertSame(AlertStatus::Active, $alert->status);
        $this->assertNull($alert->acknowledged_at);
        $this->assertSame(1, NetworkAlert::query()->where('dedup_key', "interface:{$wan->id}:utilization")->count());

        // Just below the warning threshold, inside the margin: the alert stays open.
        $this->samples($device, $wan, $warning - 2.0);
        $this->evaluate();
        $this->assertTrue($alert->refresh()->status->isOpen());
    }

    public function test_utilization_that_falls_well_below_the_threshold_resolves_the_alert(): void
    {
        [$warning] = $this->thresholds();
        [$device, $wan] = $this->deviceWithWan();

        $this->samples($device, $wan, $warning + 1.0);
        $this->evaluate();

        $this->samples($device, $wan, max(0.0, $warning - 15.0));
        $result = $this->evaluate();

        $this->assertSame(1, $result['resolved']);

        $alert = NetworkAlert::query()->where('dedup_key', "interface:{$wan->id}:utilization")->firstOrFail();

        $this->assertSame(AlertStatus::Resolved, $alert->status);
        $this->assertNull($alert->resolved_by);
    }

    public function test_failed_samples_never_raise_a_utilization_alert(): void
    {
        [$device, $wan] = $this->deviceWithWan();

        DB::table('traffic_samples')->insert([
            'monitored_device_id' => $device->id,
            'network_interface_id' => $wan->id,
            'sampled_at' => $this->now->subMinute()->format('Y-m-d H:i:s'),
            'in_mbps' => null,
            'out_mbps' => null,
            'utilization_percent' => null,
            'elapsed_seconds' => null,
            'source' => 'mock',
            'status' => 'failed',
            'is_successful' => false,
            'created_at' => $this->now->format('Y-m-d H:i:s'),
        ]);

        $this->evaluate();

        $this->assertSame(0, NetworkAlert::query()->where('type', 'like', 'utilization%')->count());
    }

    public function test_missing_measurements_raise_a_system_alert_that_clears_when_data_arrives(): void
    {
        $minutes = (int) app(SettingsService::class)->get('stale_data_minutes');
        [$device, $wan] = $this->deviceWithWan();

        // Nothing was ever measured and the device has been there longer than the stale-data window.
        $later = $this->now->addMinutes($minutes + 5);

        app(AlertEvaluator::class)->evaluate($later);

        $alert = NetworkAlert::query()->where('dedup_key', 'collector:no-data')->firstOrFail();

        $this->assertSame(AlertType::NoRecentData, $alert->type);
        $this->assertNull($alert->monitored_device_id);
        $this->assertSame(AlertStatus::Active, $alert->status);

        // A fresh measurement arrives.
        $this->samples($device, $wan, 5.0, $later);

        app(AlertEvaluator::class)->evaluate($later);

        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
    }

    public function test_a_device_that_was_just_added_is_given_time_before_the_collector_alert(): void
    {
        $this->deviceWithWan();

        $this->evaluate();

        $this->assertSame(0, NetworkAlert::query()->where('dedup_key', 'collector:no-data')->count());
    }

    public function test_demo_devices_are_ignored_while_a_real_driver_is_active(): void
    {
        config(['campusnet.driver' => 'snmp']);

        $this->device(['is_demo' => true, 'last_status' => 'unreachable', 'last_successful_poll_at' => null]);

        $this->evaluate();

        $this->assertSame(0, NetworkAlert::query()->count());
    }

    public function test_real_devices_are_ignored_in_demo_mode(): void
    {
        $this->device(['is_demo' => false, 'last_status' => 'unreachable', 'last_successful_poll_at' => null]);

        $this->evaluate();

        $this->assertSame(0, NetworkAlert::query()->where('type', AlertType::DeviceUnreachable->value)->count());
    }

    public function test_switching_a_device_off_resolves_its_open_alerts(): void
    {
        $device = $this->device(['last_status' => 'unreachable', 'last_successful_poll_at' => null]);

        $this->evaluate();
        $this->assertSame(1, NetworkAlert::query()->open()->where('monitored_device_id', $device->id)->count());

        $device->forceFill(['is_enabled' => false])->save();

        $this->evaluate();

        $alert = NetworkAlert::query()->where('monitored_device_id', $device->id)->firstOrFail();

        $this->assertSame(AlertStatus::Resolved, $alert->status);
        $this->assertStringContainsString('turned off', (string) $alert->resolution_note);
    }

    /**
     * @return array{opened: int, updated: int, resolved: int}
     */
    private function evaluate(): array
    {
        return app(AlertEvaluator::class)->evaluate($this->now);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function thresholds(): array
    {
        $settings = app(SettingsService::class)->all();

        return [(float) $settings['warning_threshold_percent'], (float) $settings['critical_threshold_percent']];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function device(array $attributes = []): MonitoredDevice
    {
        $device = new MonitoredDevice(['name' => 'Core router', 'management_ip' => '192.0.2.'.random_int(1, 250)]);
        $device->forceFill(array_merge(['is_demo' => true, 'is_enabled' => true], $attributes))->save();

        return $device;
    }

    /**
     * @return array{0: MonitoredDevice, 1: NetworkInterface}
     */
    private function deviceWithWan(): array
    {
        $device = $this->device(['last_status' => 'online', 'last_successful_poll_at' => $this->now]);

        $wan = NetworkInterface::create([
            'monitored_device_id' => $device->id,
            'if_index' => 2,
            'name' => 'ether1-WAN',
            'role' => InterfaceRole::Wan,
            'is_monitored' => true,
            'oper_status' => 'up',
            'speed_mbps' => 100,
        ]);

        return [$device, $wan];
    }

    /** Replaces the recent samples of an interface with three valid samples of the same utilization. */
    private function samples(MonitoredDevice $device, NetworkInterface $interface, float $utilization, ?CarbonImmutable $around = null): void
    {
        $around ??= $this->now;

        DB::table('traffic_samples')->where('network_interface_id', $interface->id)->delete();

        foreach ([1, 2, 3] as $minutesAgo) {
            $at = $around->subMinutes($minutesAgo);

            DB::table('traffic_samples')->insert([
                'monitored_device_id' => $device->id,
                'network_interface_id' => $interface->id,
                'sampled_at' => $at->format('Y-m-d H:i:s'),
                'in_mbps' => $utilization,
                'out_mbps' => 1.0,
                'utilization_percent' => $utilization,
                'elapsed_seconds' => 60,
                'source' => 'mock',
                'status' => 'ok',
                'is_successful' => true,
                'created_at' => $at->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /** One recent valid sample on a LAN interface, so the device counts as being measured. */
    private function freshSample(MonitoredDevice $device): void
    {
        $lan = NetworkInterface::create([
            'monitored_device_id' => $device->id,
            'if_index' => 1,
            'name' => 'bridge-LAN',
            'role' => InterfaceRole::Lan,
            'is_monitored' => true,
            'oper_status' => 'up',
            'speed_mbps' => 1000,
        ]);

        DB::table('traffic_samples')->insert([
            'monitored_device_id' => $device->id,
            'network_interface_id' => $lan->id,
            'sampled_at' => $this->now->subMinute()->format('Y-m-d H:i:s'),
            'in_mbps' => 1.0,
            'out_mbps' => 1.0,
            'utilization_percent' => null,
            'elapsed_seconds' => 60,
            'source' => 'mock',
            'status' => 'ok',
            'is_successful' => true,
            'created_at' => $this->now->format('Y-m-d H:i:s'),
        ]);
    }
}