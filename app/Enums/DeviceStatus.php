<?php

declare(strict_types=1);

namespace App\Enums;

enum DeviceStatus: string
{
    /** Never polled successfully or failed — a device is never "online" by default. */
    case Unknown = 'unknown';
    case Online = 'online';
    case Unreachable = 'unreachable';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Online => 'Online',
            self::Unreachable => 'Unreachable',
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