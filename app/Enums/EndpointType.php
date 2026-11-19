<?php

declare(strict_types=1);

namespace App\Enums;

/** What kind of client device a registered member device is (not the monitored router/switch). */
enum EndpointType: string
{
    case Laptop = 'laptop';
    case Desktop = 'desktop';
    case Phone = 'phone';
    case Tablet = 'tablet';
    case Printer = 'printer';
    case AccessPoint = 'access_point';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Laptop => 'Laptop',
            self::Desktop => 'Desktop',
            self::Phone => 'Phone',
            self::Tablet => 'Tablet',
            self::Printer => 'Printer',
            self::AccessPoint => 'Access point',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}