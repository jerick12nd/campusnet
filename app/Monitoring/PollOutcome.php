<?php

declare(strict_types=1);

namespace App\Monitoring;

final readonly class PollOutcome
{
    public function __construct(
        public bool $successful,
        public int $samples = 0,
        public ?string $error = null,
    ) {}
}