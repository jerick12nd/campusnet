<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DemoTraffic;
use PHPUnit\Framework\TestCase;

class DemoTrafficTest extends TestCase
{
    public function test_the_load_always_stays_between_zero_and_one(): void
    {
        foreach (range(0, 23) as $hour) {
            foreach (range(1, 7) as $weekday) {
                $load = DemoTraffic::loadFactor($hour, $weekday);

                $this->assertGreaterThan(0.0, $load);
                $this->assertLessThanOrEqual(1.0, $load);
            }
        }
    }

    public function test_early_afternoon_is_busier_than_the_middle_of_the_night(): void
    {
        $this->assertGreaterThan(DemoTraffic::loadFactor(3, 2) * 5, DemoTraffic::loadFactor(13, 2));
    }

    public function test_the_peak_is_around_one_in_the_afternoon(): void
    {
        $peakHour = 0;
        $peak = 0.0;

        foreach (range(0, 23) as $hour) {
            $load = DemoTraffic::loadFactor($hour, 3);

            if ($load > $peak) {
                $peak = $load;
                $peakHour = $hour;
            }
        }

        $this->assertContains($peakHour, [12, 13]);
    }

    public function test_weekends_are_much_lighter_than_weekdays(): void
    {
        $weekday = DemoTraffic::loadFactor(13, 3);

        $this->assertEqualsWithDelta($weekday * 0.35, DemoTraffic::loadFactor(13, 6), 0.0002);
        $this->assertEqualsWithDelta($weekday * 0.35, DemoTraffic::loadFactor(13, 7), 0.0002);
        $this->assertSame(DemoTraffic::loadFactor(13, 1), DemoTraffic::loadFactor(13, 5));
    }

    public function test_it_is_deterministic(): void
    {
        $this->assertSame(DemoTraffic::loadFactor(9, 4), DemoTraffic::loadFactor(9, 4));
    }
}