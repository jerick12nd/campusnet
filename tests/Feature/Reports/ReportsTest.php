<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\InterfaceRole;
use App\Models\MonitoredDevice;
use App\Models\NetworkAlert;
use App\Models\NetworkInterface;
use App\Models\User;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campusnet.driver' => 'mock']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/reports')->assertRedirect();
        $this->get('/reports/download')->assertRedirect();
    }

    public function test_a_viewer_can_preview_a_report_but_not_download_it(): void
    {
        [$device, $wan] = $this->topology();
        $this->sample($device, $wan, '2026-11-02 01:00:00', 10.0, 2.0, 10.0);

        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)
            ->get('/reports?type=traffic_daily&from=2026-11-01&to=2026-11-02')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/index')
                ->where('report.type', 'traffic_daily')
                ->where('report.from_date', '2026-11-01')
                ->where('report.to_date', '2026-11-02')
                ->where('report.max_days', 92)
                ->where('report.mode.demo', true)
                ->where('report.total_rows', 1)
                ->has('report.types', 3)
                ->has('report.columns')
                ->where('can.export', false));

        $this->actingAs($viewer)->get('/reports/download?type=traffic_daily')->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'report.exported']);
    }

    public function test_the_default_period_is_the_last_seven_days(): void
    {
        $this->actingAs($this->userWithRole('viewer'))
            ->get('/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.type', 'traffic_daily')
                ->where('report.to_date', fn (string $date) => $date >= '2026-11-02' && $date <= '2026-11-03')
                ->has('report.rows', 0));
    }

    public function test_the_traffic_report_downloads_as_csv_with_correct_figures_and_is_audited(): void
    {
        [$device, $wan] = $this->topology();

        // Two valid samples (10 and 20 Mbps in) and one failed sample that must not count.
        $this->sample($device, $wan, '2026-11-02 01:00:00', 10.0, 2.0, 10.0);
        $this->sample($device, $wan, '2026-11-02 01:01:00', 20.0, 4.0, 20.0);
        $this->sample($device, $wan, '2026-11-02 01:02:00', null, null, null, failed: true);

        $admin = $this->userWithRole('network_admin');

        $response = $this->actingAs($admin)->get('/reports/download?type=traffic_daily&from=2026-11-01&to=2026-11-02');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('campusnet-traffic-daily-demo-2026-11-01_2026-11-02.csv', (string) $response->headers->get('content-disposition'));

        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'The file must start with a UTF-8 byte order mark so Excel reads it correctly.');

        $lines = explode("\r\n", rtrim(substr($content, 3), "\r\n"));

        $this->assertCount(2, $lines, 'One header line and one data line.');

        $header = str_getcsv($lines[0], ',', '"', '');
        $row = str_getcsv($lines[1], ',', '"', '');

        $this->assertStringStartsWith('Date (', $header[0]);
        $this->assertSame('Core router', $row[1]);
        $this->assertSame('ether1-WAN', $row[2]);
        $this->assertSame('2026-11-02', $row[0]);
        $this->assertEqualsWithDelta(15.0, (float) $row[4], 0.01);   // average inbound
        $this->assertEqualsWithDelta(20.0, (float) $row[6], 0.01);   // peak inbound
        $this->assertEqualsWithDelta(225.0, (float) $row[8], 0.01);  // (10*60 + 20*60) Mbit / 8 = MB in
        $this->assertSame('2', $row[12]);                             // valid samples only
        $this->assertSame('mock', $row[13]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported']);
    }

    public function test_the_alerts_report_lists_alerts_raised_in_the_period(): void
    {
        [$device] = $this->topology();

        $this->alert($device, 'Inside the period', '2026-11-02 01:00:00');
        $this->alert($device, 'Before the period', '2026-10-01 01:00:00', 'older');

        $admin = $this->userWithRole('network_admin');

        $content = $this->actingAs($admin)
            ->get('/reports/download?type=alerts&from=2026-11-01&to=2026-11-02')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Inside the period', $content);
        $this->assertStringNotContainsString('Before the period', $content);
    }

    public function test_the_availability_report_shows_the_success_rate_per_device(): void
    {
        [$device, $wan] = $this->topology();

        $this->sample($device, $wan, '2026-11-02 01:00:00', 10.0, 2.0, 10.0);
        $this->sample($device, $wan, '2026-11-02 01:01:00', null, null, null, failed: true);

        $admin = $this->userWithRole('network_admin');

        $content = $this->actingAs($admin)
            ->get('/reports/download?type=device_availability&from=2026-11-01&to=2026-11-02')
            ->assertOk()
            ->streamedContent();

        $lines = explode("\r\n", rtrim(substr($content, 3), "\r\n"));
        $header = str_getcsv($lines[0], ',', '"', '');
        $row = str_getcsv($lines[1], ',', '"', '');

        $this->assertSame('Core router', $row[0]);
        $this->assertSame('2', $row[array_search('Samples in period', $header, true)]);
        $this->assertSame('1', $row[array_search('Failed samples', $header, true)]);
        $this->assertEqualsWithDelta(50.0, (float) $row[array_search('Success rate (%)', $header, true)], 0.01);
    }

    public function test_a_report_without_data_is_just_the_header(): void
    {
        $content = $this->actingAs($this->userWithRole('network_admin'))
            ->get('/reports/download?type=traffic_daily&from=2026-11-01&to=2026-11-02')
            ->assertOk()
            ->streamedContent();

        $this->assertCount(1, explode("\r\n", rtrim(substr($content, 3), "\r\n")));
    }

    public function test_the_file_name_does_not_say_demo_with_a_real_driver(): void
    {
        config(['campusnet.driver' => 'snmp']);

        $response = $this->actingAs($this->userWithRole('network_admin'))->get('/reports/download?type=traffic_daily&from=2026-11-01&to=2026-11-02');

        $response->assertOk();
        $this->assertStringNotContainsString('demo', (string) $response->headers->get('content-disposition'));
    }

    public function test_demo_data_is_left_out_of_reports_once_a_real_driver_is_active(): void
    {
        [$device, $wan] = $this->topology();
        $this->sample($device, $wan, '2026-11-02 01:00:00', 10.0, 2.0, 10.0);
        $this->alert($device, 'Demo alert', '2026-11-02 01:00:00');

        config(['campusnet.driver' => 'snmp']);

        $admin = $this->userWithRole('network_admin');

        foreach (['traffic_daily', 'alerts', 'device_availability'] as $type) {
            $content = $this->actingAs($admin)->get("/reports/download?type={$type}&from=2026-11-01&to=2026-11-02")->assertOk()->streamedContent();

            $this->assertStringNotContainsString('Core router', $content, "{$type} must not contain demo devices.");
            $this->assertStringNotContainsString('Demo alert', $content);
        }
    }

    public function test_invalid_requests_are_rejected(): void
    {
        $admin = $this->userWithRole('network_admin');

        $this->actingAs($admin)->get('/reports/download?type=banana')->assertSessionHasErrors('type');
        $this->actingAs($admin)->get('/reports/download?type=alerts&from=2026-11-05&to=2026-11-01')->assertSessionHasErrors('to');
        $this->actingAs($admin)->get('/reports/download?type=alerts&from=2026-11-01')->assertSessionHasErrors('to');
        $this->actingAs($admin)->get('/reports/download?type=alerts&from=2026-01-01&to=2026-11-01')->assertSessionHasErrors('to');
        $this->actingAs($admin)->get('/reports/download?type=alerts&from=not-a-date&to=2026-11-01')->assertSessionHasErrors('from');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'report.exported']);
    }

    public function test_cells_that_look_like_formulas_are_neutralised(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://example.com\")", ReportService::csvCell('=HYPERLINK("http://example.com")'));
        $this->assertSame("'+1+1", ReportService::csvCell('+1+1'));
        $this->assertSame("'-2+3", ReportService::csvCell('-2+3'));
        $this->assertSame("'@SUM(A1)", ReportService::csvCell('@SUM(A1)'));
        $this->assertSame("'\tcmd", ReportService::csvCell("\tcmd"));

        $this->assertSame('Core router', ReportService::csvCell('Core router'));
        $this->assertSame(-5.5, ReportService::csvCell(-5.5), 'Real numbers are not text, so a minus sign is fine.');
        $this->assertSame(0, ReportService::csvCell(0));
        $this->assertSame('', ReportService::csvCell(null));
        $this->assertSame('Yes', ReportService::csvCell(true));
    }

    public function test_a_device_name_that_looks_like_a_formula_is_neutralised_in_the_file(): void
    {
        $device = new MonitoredDevice(['name' => '=cmd|calc', 'management_ip' => '192.0.2.9']);
        $device->forceFill(['is_demo' => true])->save();

        $content = $this->actingAs($this->userWithRole('network_admin'))
            ->get('/reports/download?type=device_availability&from=2026-11-01&to=2026-11-02')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString("'=cmd|calc", $content);
        $this->assertStringNotContainsString(",=cmd|calc", $content);
        $this->assertStringNotContainsString("\r\n=cmd|calc", $content);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /**
     * @return array{0: MonitoredDevice, 1: NetworkInterface}
     */
    private function topology(): array
    {
        $device = new MonitoredDevice(['name' => 'Core router', 'management_ip' => '192.0.2.1']);
        $device->forceFill(['is_demo' => true, 'is_enabled' => true])->save();

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

    private function sample(MonitoredDevice $device, NetworkInterface $interface, string $at, ?float $in, ?float $out, ?float $utilization, bool $failed = false): void
    {
        DB::table('traffic_samples')->insert([
            'monitored_device_id' => $device->id,
            'network_interface_id' => $interface->id,
            'sampled_at' => $at,
            'in_mbps' => $in,
            'out_mbps' => $out,
            'utilization_percent' => $utilization,
            'elapsed_seconds' => $failed ? null : 60,
            'source' => 'mock',
            'status' => $failed ? 'failed' : 'ok',
            'is_successful' => ! $failed,
            'created_at' => $at,
        ]);
    }

    private function alert(MonitoredDevice $device, string $title, string $triggeredAt, string $key = 'k'): NetworkAlert
    {
        return NetworkAlert::create([
            'type' => AlertType::DeviceUnreachable,
            'severity' => AlertSeverity::Critical,
            'status' => AlertStatus::Active,
            'title' => $title,
            'monitored_device_id' => $device->id,
            'dedup_key' => "device:{$device->id}:{$key}",
            'triggered_at' => CarbonImmutable::parse($triggeredAt, 'UTC'),
            'last_seen_at' => CarbonImmutable::parse($triggeredAt, 'UTC'),
        ]);
    }
}