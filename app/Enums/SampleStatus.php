<?php

declare(strict_types=1);

namespace App\Enums;

enum SampleStatus: string
{
    /** Valid measurement with a computed rate. */
    case Ok = 'ok';
    /** First sample for an interface: counters stored, no rate can be computed yet. */
    case Baseline = 'baseline';
    /** Counter went backwards (device reboot or counter reset): rate not computed. */
    case CounterReset = 'counter_reset';
    /** The poll itself failed (timeout, auth error, unreachable). */
    case Failed = 'failed';
    /** Poll succeeded but the measurement was rejected (e.g. impossible rate). */
    case Invalid = 'invalid';

    public function hasValidRate(): bool
    {
        return $this === self::Ok;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}