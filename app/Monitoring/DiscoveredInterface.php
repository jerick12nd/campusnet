<?php

declare(strict_types=1);

namespace App\Monitoring;

final readonly class DiscoveredInterface
{
    public function __construct(
        public int $ifIndex,
        public string $name,
        public ?string $description = null,
        public ?string $operStatus = null,
        public ?int $speedMbps = null,
    ) {}
}