<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SampleStatus;
use App\Monitoring\CounterRate;
use PHPUnit\Framework\TestCase;

class CounterRateTest extends TestCase
{
    public function test_first_reading_is_a_baseline(): void
    {
        $result = CounterRate::compute(null, null, 1_000, 2_000, null);

        $this->assertSame(SampleStatus::Baseline, $result->status);
        $this->assertNull($result->inMbps);
    }

    public function test_rate_comes_from_the_counter_difference(): void
    {
        // 7,500,000 bytes in 60 s = 1 Mbps in; 3,750,000 bytes = 0.5 Mbps out.
        $result = CounterRate::compute(1_000_000, 500_000, 8_500_000, 4_250_000, 60);

        $this->assertSame(SampleStatus::Ok, $result->status);
        $this->assertSame(1.0, $result->inMbps);
        $this->assertSame(0.5, $result->outMbps);
    }

    public function test_a_32_bit_counter_wrap_is_handled(): void
    {
        $previous = 4_294_967_296 - 6_500_000;
        $result = CounterRate::compute($previous, $previous, 1_000_000, 1_000_000, 60, 32);

        $this->assertSame(SampleStatus::Ok, $result->status);
        $this->assertSame(1.0, $result->inMbps);
    }

    public function test_a_64_bit_counter_going_backwards_is_a_reset(): void
    {
        $result = CounterRate::compute(5_000, 5_000, 100, 100, 60, 64);

        $this->assertSame(SampleStatus::CounterReset, $result->status);
        $this->assertNull($result->inMbps);
    }

    public function test_an_impossible_rate_is_rejected(): void
    {
        // 75,000,000 bytes in 60 s = 10 Mbps on a 5 Mbps link.
        $result = CounterRate::compute(0, 0, 75_000_000, 0, 60, 64, 5.0);

        $this->assertSame(SampleStatus::Invalid, $result->status);
    }

    public function test_an_impossible_rate_after_a_wrap_means_the_counter_was_reset(): void
    {
        $result = CounterRate::compute(1_000_000_000, 0, 10, 0, 60, 32, 100.0);

        $this->assertSame(SampleStatus::CounterReset, $result->status);
    }

    public function test_zero_elapsed_time_is_invalid(): void
    {
        $result = CounterRate::compute(1, 1, 2, 2, 0);

        $this->assertSame(SampleStatus::Invalid, $result->status);
    }
}