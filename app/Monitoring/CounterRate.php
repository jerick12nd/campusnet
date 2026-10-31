<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Enums\SampleStatus;

final class CounterRate
{
    private const WRAP_32 = 4_294_967_296;

    /**
     * Turn two counter readings into a rate in Mbps.
     *
     * @param  int  $counterBits  32 or 64
     * @param  float|null  $maxMbps  highest believable rate (link speed plus margin); null = no check
     */
    public static function compute(
        ?int $previousIn,
        ?int $previousOut,
        int $currentIn,
        int $currentOut,
        ?int $elapsedSeconds,
        int $counterBits = 64,
        ?float $maxMbps = null,
    ): RateResult {
        // Nothing to compare against yet: store the counters, compute no rate.
        if ($previousIn === null || $previousOut === null || $elapsedSeconds === null) {
            return new RateResult(SampleStatus::Baseline);
        }

        if ($elapsedSeconds <= 0) {
            return new RateResult(SampleStatus::Invalid);
        }

        $in = self::delta($previousIn, $currentIn, $counterBits);
        $out = self::delta($previousOut, $currentOut, $counterBits);

        if ($in === null || $out === null) {
            return new RateResult(SampleStatus::CounterReset);
        }

        $inMbps = $in['bytes'] * 8 / $elapsedSeconds / 1_000_000;
        $outMbps = $out['bytes'] * 8 / $elapsedSeconds / 1_000_000;

        if ($maxMbps !== null && max($inMbps, $outMbps) > $maxMbps) {
            // After assuming a 32-bit wrap the rate is still impossible, so the counter
            // was really reset (reboot). Without a wrap, the reading itself is bad.
            $wrapped = $in['wrapped'] || $out['wrapped'];

            return new RateResult($wrapped ? SampleStatus::CounterReset : SampleStatus::Invalid);
        }

        return new RateResult(SampleStatus::Ok, round($inMbps, 4), round($outMbps, 4));
    }

    /**
     * @return array{bytes: int, wrapped: bool}|null  null = counter went backwards and cannot be a wrap
     */
    private static function delta(int $previous, int $current, int $bits): ?array
    {
        if ($current >= $previous) {
            return ['bytes' => $current - $previous, 'wrapped' => false];
        }

        if ($bits === 32) {
            return ['bytes' => $current + self::WRAP_32 - $previous, 'wrapped' => true];
        }

        return null;
    }
}