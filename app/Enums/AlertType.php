<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertType: string
{
    case UtilizationWarning = 'utilization_warning';
    case UtilizationCritical = 'utilization_critical';
    case DeviceUnreachable = 'device_unreachable';
    case CollectorFailure = 'collector_failure';
    case NoRecentData = 'no_recent_data';
    case DeviceRecovered = 'device_recovered';

    public function label(): string
    {
        return match ($this) {
            self::UtilizationWarning => 'Utilization warning',
            self::UtilizationCritical => 'Utilization critical',
            self::DeviceUnreachable => 'Device unreachable',
            self::CollectorFailure => 'Collector failure',
            self::NoRecentData => 'No recent measurements',
            self::DeviceRecovered => 'Device recovered',
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