<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case NetworkAdmin = 'network_admin';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Administrator',
            self::NetworkAdmin => 'ICT / Network Administrator',
            self::Viewer => 'Read-Only Viewer',
        };
    }

    /**
     * Permissions granted to this role. These strings are registered as
     * Laravel gates in CampusNetServiceProvider, so the backend can enforce
     * them with `can:` middleware, `$this->authorize()` and policies.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        $viewing = [
            'dashboard.view',
            'devices.view',
            'interfaces.view',
            'traffic.view',
            'alerts.view',
            'reports.view',
        ];

        $operating = [
            ...$viewing,
            'devices.manage',
            'interfaces.manage',
            'alerts.manage',
            'reports.export',
            'settings.view',
        ];

        return match ($this) {
            self::Viewer => $viewing,
            self::NetworkAdmin => $operating,
            self::SuperAdmin => [
                ...$operating,
                'settings.manage',
                'users.manage',
                'audit.view',
            ],



            
        };
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return self::SuperAdmin->permissions();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}