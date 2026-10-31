<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Higher number = more severe. Used for sorting and escalation. */
    public function rank(): int
    {
        return match ($this) {
            self::Info => 1,
            self::Warning => 2,
            self::Critical => 3,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}