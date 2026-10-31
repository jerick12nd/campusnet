<?php

declare(strict_types=1);

namespace App\Enums;

enum DeviceType: string
{
    case Router = 'router';
    case NetworkSwitch = 'switch';
    case AccessPoint = 'access_point';
    case Firewall = 'firewall';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Router => 'Router',
            self::NetworkSwitch => 'Switch',
            self::AccessPoint => 'Access point',
            self::Firewall => 'Firewall',
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