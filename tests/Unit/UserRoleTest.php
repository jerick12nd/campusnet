<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_viewer_has_read_only_permissions(): void
    {
        $viewer = UserRole::Viewer;

        $this->assertTrue($viewer->can('dashboard.view'));
        $this->assertTrue($viewer->can('devices.view'));
        $this->assertTrue($viewer->can('reports.view'));

        foreach (['devices.manage', 'interfaces.manage', 'alerts.manage', 'reports.export', 'settings.view', 'settings.manage', 'users.manage', 'audit.view'] as $permission) {
            $this->assertFalse($viewer->can($permission), "Viewer must not have {$permission}");
        }
    }

    public function test_network_admin_can_operate_but_not_administer(): void
    {
        $admin = UserRole::NetworkAdmin;

        foreach (['devices.manage', 'interfaces.manage', 'alerts.manage', 'reports.export', 'settings.view'] as $permission) {
            $this->assertTrue($admin->can($permission), "Network admin should have {$permission}");
        }

        foreach (['settings.manage', 'users.manage', 'audit.view'] as $permission) {
            $this->assertFalse($admin->can($permission), "Network admin must not have {$permission}");
        }
    }

    public function test_super_admin_has_every_permission(): void
    {
        foreach (UserRole::allPermissions() as $permission) {
            $this->assertTrue(UserRole::SuperAdmin->can($permission));
        }

        $this->assertContains('users.manage', UserRole::SuperAdmin->permissions());
        $this->assertContains('audit.view', UserRole::SuperAdmin->permissions());
    }

    public function test_role_values_round_trip(): void
    {
        $this->assertSame(['super_admin', 'network_admin', 'viewer'], UserRole::values());
        $this->assertSame(UserRole::NetworkAdmin, UserRole::from('network_admin'));
    }
}