<?php

declare(strict_types=1);

namespace App\Enums;

enum SnmpVersion: string
{
    case V2c = 'v2c';
    case V3 = 'v3';

    public function label(): string
    {
        return match ($this) {
            self::V2c => 'SNMPv2c (trusted management network only)',
            self::V3 => 'SNMPv3 (recommended)',
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