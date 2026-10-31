<?php

declare(strict_types=1);

namespace App\Support;

final class Bandwidth
{
    /**
     * Utilization of the configured internet capacity, in percent.
     *
     * Internet links are full duplex, so the capacity applies to each direction
     * separately; the busiest direction is what saturates the link. The value is
     * not clamped at 100: a result above 100 means the capacity setting is lower
     * than what the link actually carries, which an administrator should notice.
     */
    public static function utilizationPercent(float $inMbps, float $outMbps, float $capacityMbps): ?float
    {
        if ($capacityMbps <= 0) {
            return null;
        }

        return round(max($inMbps, $outMbps, 0.0) / $capacityMbps * 100, 2);
    }
}