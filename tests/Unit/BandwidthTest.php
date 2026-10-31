<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Bandwidth;
use PHPUnit\Framework\TestCase;

class BandwidthTest extends TestCase
{
    public function test_it_uses_the_busiest_direction(): void
    {
        $this->assertSame(40.0, Bandwidth::utilizationPercent(40, 10, 100));
        $this->assertSame(70.0, Bandwidth::utilizationPercent(5, 70, 100));
    }

    public function test_it_rounds_to_two_decimals(): void
    {
        $this->assertSame(33.33, Bandwidth::utilizationPercent(1, 0, 3));
    }

    public function test_it_is_not_clamped_at_one_hundred_percent(): void
    {
        $this->assertSame(130.0, Bandwidth::utilizationPercent(130, 20, 100));
    }

    public function test_no_traffic_is_zero_percent(): void
    {
        $this->assertSame(0.0, Bandwidth::utilizationPercent(0, 0, 100));
    }

    public function test_negative_rates_never_produce_a_negative_percentage(): void
    {
        $this->assertSame(0.0, Bandwidth::utilizationPercent(-5, -1, 100));
    }

    public function test_a_missing_or_invalid_capacity_gives_no_percentage_instead_of_dividing_by_zero(): void
    {
        $this->assertNull(Bandwidth::utilizationPercent(10, 10, 0));
        $this->assertNull(Bandwidth::utilizationPercent(10, 10, -100));
    }
}