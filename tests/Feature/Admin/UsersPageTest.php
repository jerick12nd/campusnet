<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UsersPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected(): void
    {
        $this->get('/users')->assertRedirect();
    }

    public function test_only_a_super_administrator_can_manage_users(): void
    {
        $target = $this->userWithRole('viewer');

        foreach (['viewer', 'network_admin'] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)->get('/users')->assertForbidden();
            $this->actingAs($user)->patch("/users/{$target->id}", ['role' => 'super_admin'])->assertForbidden();
        }

        $this->assertSame('viewer', $this->roleOf($target));
    }

    public function test_the_list_shows_every_account_with_role_status_and_a_summary(): void
    {
        $admin = $this->userWithRole('super_admin', ['name' => 'Alice Admin']);
        $this->userWithRole('viewer', ['name' => 'Bob Viewer']);
        $this->userWithRole('network_admin', ['name' => 'Carol Net', 'is_active' => false]);

        $this->actingAs($admin)
            ->get('/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('users/index')
                ->where('stats.total', 3)
                ->where('stats.active', 2)
                ->where('stats.inactive', 1)
                ->where('stats.super_admins', 1)
                ->has('roles', 3)
                ->has('users.data', 3)
                ->where('users.data.0.name', 'Alice Admin')
                ->where('users.data.0.is_self', true)
                ->where('users.data.0.role.value', 'super_admin')
                ->where('users.data.2.is_active', false));
    }

    public function test_filters_narrow_the_list_and_invalid_values_are_rejected(): void
    {
        $admin = $this->userWithRole('super_admin', ['name' => 'Alice Admin']);
        $this->userWithRole('viewer', ['name' => 'Bob Viewer', 'email' => 'bob@example.com']);
        $this->userWithRole('network_admin', ['name' => 'Carol Net', 'is_active' => false]);

        $this->actingAs($admin)
            ->get('/users?role=viewer')
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Bob Viewer'));

        $this->actingAs($admin)
            ->get('/users?status=inactive')
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Carol Net'));

        $this->actingAs($admin)
            ->get('/users?search=bob@example')
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Bob Viewer'));

        $this->actingAs($admin)->get('/users?role=owner')->assertSessionHasErrors('role');
        $this->actingAs($admin)->get('/users?status=banned')->assertSessionHasErrors('status');
    }

    public function test_a_role_change_is_saved_and_audited(): void
    {
        $admin = $this->userWithRole('super_admin');
        $target = $this->userWithRole('viewer', ['name' => 'Bob Viewer']);

        $this->actingAs($admin)
            ->patch("/users/{$target->id}", ['role' => 'network_admin'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('network_admin', $this->roleOf($target));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role_changed', 'user_id' => $admin->id]);
    }

    public function test_an_account_can_be_deactivated_and_activated_again(): void
    {
        $admin = $this->userWithRole('super_admin');
        $target = $this->userWithRole('viewer');

        $this->actingAs($admin)->patch("/users/{$target->id}", ['is_active' => false])->assertRedirect()->assertSessionHas('success');

        $this->assertFalse((bool) $target->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deactivated']);

        $this->actingAs($admin)->patch("/users/{$target->id}", ['is_active' => true])->assertRedirect();

        $this->assertTrue((bool) $target->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.activated']);
    }

    public function test_nobody_can_change_their_own_role_or_deactivate_themselves(): void
    {
        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)->patch("/users/{$admin->id}", ['role' => 'viewer'])->assertRedirect()->assertSessionHas('error');
        $this->actingAs($admin)->patch("/users/{$admin->id}", ['is_active' => false])->assertRedirect()->assertSessionHas('error');

        $this->assertSame('super_admin', $this->roleOf($admin));
        $this->assertTrue((bool) $admin->refresh()->is_active);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.role_changed']);
    }

    public function test_one_super_administrator_can_demote_another_while_a_second_one_remains(): void
    {
        $admin = $this->userWithRole('super_admin');
        $other = $this->userWithRole('super_admin');

        $this->actingAs($admin)->patch("/users/{$other->id}", ['role' => 'viewer'])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('viewer', $this->roleOf($other));
    }

    public function test_sending_the_current_values_changes_nothing_and_is_not_audited(): void
    {
        $admin = $this->userWithRole('super_admin');
        $target = $this->userWithRole('viewer');

        $this->actingAs($admin)->patch("/users/{$target->id}", ['role' => 'viewer', 'is_active' => true])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.role_changed']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.deactivated']);
    }

    public function test_invalid_changes_are_rejected(): void
    {
        $admin = $this->userWithRole('super_admin');
        $target = $this->userWithRole('viewer');

        $this->actingAs($admin)->patch("/users/{$target->id}", [])->assertSessionHasErrors(['role', 'is_active']);
        $this->actingAs($admin)->patch("/users/{$target->id}", ['role' => 'owner'])->assertSessionHasErrors('role');
        $this->actingAs($admin)->patch("/users/{$target->id}", ['is_active' => 'maybe'])->assertSessionHasErrors('is_active');

        $this->assertSame('viewer', $this->roleOf($target));
    }

    private function roleOf(User $user): string
    {
        $role = $user->refresh()->role;

        return $role instanceof UserRole ? $role->value : (string) $role;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true], $attributes));
    }
}