<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DeviceStatus;
use App\Monitoring\ConnectionTestResult;
use PHPUnit\Framework\TestCase;

class ConnectionTestResultTest extends TestCase
{
    public function test_reaching_a_device_marks_it_online_and_is_stored(): void
    {
        $result = ConnectionTestResult::reached('It answered.', 14, ['sys_name' => 'core']);

        $this->assertTrue($result->success);
        $this->assertSame(DeviceStatus::Online, $result->status);
        $this->assertSame(14, $result->latencyMs);
        $this->assertFalse($result->simulated);
        $this->assertTrue($result->shouldPersist());
    }

    public function test_an_unreachable_device_is_stored_as_unreachable(): void
    {
        $result = ConnectionTestResult::unreachable('No response.');

        $this->assertFalse($result->success);
        $this->assertSame(DeviceStatus::Unreachable, $result->status);
        $this->assertTrue($result->shouldPersist());
    }

    public function test_a_setup_problem_learns_nothing_and_is_never_stored(): void
    {
        $result = ConnectionTestResult::notPerformed('The PHP SNMP extension is not enabled.');

        $this->assertFalse($result->success);
        $this->assertNull($result->status);
        $this->assertFalse($result->simulated);
        $this->assertFalse($result->shouldPersist());
    }

    public function test_a_demo_result_is_flagged_and_never_stored(): void
    {
        $result = ConnectionTestResult::demo('DEMO MODE: simulated.');

        $this->assertTrue($result->success);
        $this->assertNull($result->status);
        $this->assertTrue($result->simulated);
        $this->assertFalse($result->shouldPersist());
    }

    public function test_it_serialises_for_the_browser_with_plain_values(): void
    {
        $this->assertSame([
            'success' => true,
            'status' => 'online',
            'message' => 'It answered.',
            'latency_ms' => 14,
            'details' => ['sys_name' => 'core'],
            'simulated' => false,
        ], ConnectionTestResult::reached('It answered.', 14, ['sys_name' => 'core'])->toArray());

        $this->assertNull(ConnectionTestResult::demo('x')->toArray()['status']);
    }
}