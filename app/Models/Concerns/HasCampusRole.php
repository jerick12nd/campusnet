<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\UserRole;

/**
 * Adds CampusNet role and account-status behaviour to the User model.
 *
 * Add `use HasCampusRole;` to App\Models\User. The casts are merged by the
 * initializeHasCampusRole() hook, so the starter kit's own casts() method
 * does not need to be edited.
 *
 * `role` and `is_active` are intentionally NOT mass-assignable. Set them
 * explicitly (e.g. through the campusnet:create-user command or the user
 * management screen) so a crafted request can never promote an account.
 */
trait HasCampusRole
{
    public function initializeHasCampusRole(): void
    {
        $this->mergeCasts([
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ]);

        // Least-privilege in-memory defaults for brand-new instances. They match the
        // column defaults, so a factory-created user is a viewer and active even
        // before it is reloaded. Models hydrated from the database overwrite these.
        $this->attributes['role'] ??= UserRole::Viewer->value;
        $this->attributes['is_active'] ??= true;
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->is_active && $this->role instanceof UserRole && $this->role->can($permission);
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        if (! $this->is_active || ! $this->role instanceof UserRole) {
            return [];
        }

        return $this->role->permissions();
    }
}