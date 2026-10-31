<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\User;

trait CreatesUsers
{
    /**
     * role and is_active are not mass-assignable, so they are set explicitly.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function userWithRole(UserRole $role, bool $active = true, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        $user->forceFill(['role' => $role, 'is_active' => $active])->save();

        return $user->refresh();
    }
}