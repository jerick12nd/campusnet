<?php

declare(strict_types=1);

namespace App\Monitoring;

/** One interface's counters as reported by a driver at one moment. */
final readonly class InterfaceCounters
{
    public function __construct(
        public int $ifIndex,
        public int $inOctets,
        public int $outOctets,
        public ?string $operStatus = null,
        public ?int $speedMbps = null,
        /** 32 or 64: decides whether a smaller value means "wrapped" or "reset". */
        public int $counterBits = 64,
    ) {}
}