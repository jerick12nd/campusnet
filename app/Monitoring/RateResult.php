<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Enums\SampleStatus;

final readonly class RateResult
{
    public function __construct(
        public SampleStatus $status,
        public ?float $inMbps = null,
        public ?float $outMbps = null,
    ) {}
}