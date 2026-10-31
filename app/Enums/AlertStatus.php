<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertStatus: string
{
    case Active = 'active';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** An alert is "open" until it is resolved, even when acknowledged. */
    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}