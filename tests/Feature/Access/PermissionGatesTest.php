<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PermissionGatesTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    public function test_gates_follow_the_role_matrix(): void
    {
        $viewer = $this->userWithRole(UserRole::Viewer);
        $network = $this->userWithRole(UserRole::NetworkAdmin);
        $super = $this->userWithRole(UserRole::SuperAdmin);

        $this->assertTrue(Gate::forUser($viewer)->allows('dashboard.view'));
        $this->assertTrue(Gate::forUser($viewer)->denies('devices.manage'));
        $this->assertTrue(Gate::forUser($viewer)->denies('users.manage'));

        $this->assertTrue(Gate::forUser($network)->allows('devices.manage'));
        $this->assertTrue(Gate::forUser($network)->denies('settings.manage'));
        $this->assertTrue(Gate::forUser($network)->denies('audit.view'));

        $this->assertTrue(Gate::forUser($super)->allows('users.manage'));
        $this->assertTrue(Gate::forUser($super)->allows('audit.view'));
        $this->assertTrue(Gate::forUser($super)->allows('settings.manage'));
    }

    public function test_deactivated_user_is_denied_every_permission(): void
    {
        $inactiveSuper = $this->userWithRole(UserRole::SuperAdmin, false);

        $this->assertTrue(Gate::forUser($inactiveSuper)->denies('dashboard.view'));
        $this->assertTrue(Gate::forUser($inactiveSuper)->denies('users.manage'));
        $this->assertSame([], $inactiveSuper->permissions());
    }

    public function test_role_cannot_be_mass_assigned(): void
    {
        $user = $this->userWithRole(UserRole::Viewer);

        $user->fill(['role' => UserRole::SuperAdmin->value, 'is_active' => false])->save();

        $user->refresh();
        $this->assertSame(UserRole::Viewer, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_guests_are_redirected_from_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}