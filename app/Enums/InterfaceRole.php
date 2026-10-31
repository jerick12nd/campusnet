<?php

declare(strict_types=1);

namespace App\Enums;

enum InterfaceRole: string
{
    case Wan = 'wan';
    case Lan = 'lan';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Wan => 'WAN (campus internet link)',
            self::Lan => 'LAN',
            self::Other => 'Other',
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