<?php

declare(strict_types=1);

namespace App\Enums;

enum DataSource: string
{
    case Mock = 'mock';
    case Snmp = 'snmp';
    case Mikrotik = 'mikrotik';

    public function label(): string
    {
        return match ($this) {
            self::Mock => 'Simulated (demo)',
            self::Snmp => 'SNMP',
            self::Mikrotik => 'MikroTik RouterOS',
        };
    }

    public function isSimulated(): bool
    {
        return $this === self::Mock;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}