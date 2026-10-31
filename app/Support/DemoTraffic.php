<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shape of simulated campus traffic, used only by `campusnet:demo-data`.
 */
final class DemoTraffic
{
    /**
     * Typical campus load (0..1) for a local hour (0-23) and ISO weekday (1 = Monday).
     * Quiet at night, a broad peak around 13:00, much lighter on weekends.
     */
    public static function loadFactor(int $hour, int $isoWeekday): float
    {
        $bump = exp(-(($hour + 0.5 - 13.0) ** 2) / (2 * 3.5 ** 2));
        $load = 0.06 + 0.94 * $bump;

        return round($isoWeekday >= 6 ? $load * 0.35 : $load, 4);
    }
}