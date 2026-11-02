<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Listing and changing user accounts: role and active/inactive. Accounts are not created
 * or deleted here. A person who is deactivated is signed out on their next request.
 */
final class UserManagementService
{
    public const PER_PAGE = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{search: string, role: string, status: string}  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters, User $viewer): array
    {
        $term = '%'.$filters['search'].'%';

        $page = User::query()
            ->when($filters['search'] !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('name', 'like', $term)
                ->orWhere('email', 'like', $term)))
            ->when($filters['role'] !== '', fn (Builder $q) => $q->where('role', $filters['role']))
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $page->through(function (User $user) use ($viewer): array {
            $role = $this->roleOf($user);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => ['value' => $role->value, 'label' => $role->label()],
                'is_active' => (bool) $user->is_active,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'is_self' => $user->id === $viewer->id,
            ];
        });

        return [
            'users' => $page->toArray(),
            'stats' => $this->stats(),
            'roles' => array_map(
                fn (UserRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                UserRole::cases(),
            ),
        ];
    }

    /**
     * @param  array{role?: string, is_active?: bool}  $changes
     * @return string|null an error message, or null when the account was updated (or nothing changed)
     */
    public function update(User $target, User $actor, array $changes): ?string
    {
        $newRole = isset($changes['role']) ? UserRole::from($changes['role']) : null;
        $newActive = array_key_exists('is_active', $changes) ? (bool) $changes['is_active'] : null;

        $roleChanges = $newRole !== null && $newRole !== $this->roleOf($target);
        $statusChanges = $newActive !== null && $newActive !== (bool) $target->is_active;

        if (! $roleChanges && ! $statusChanges) {
            return null;
        }

        if ($target->id === $actor->id) {
            return 'You cannot change your own role or deactivate your own account. Ask another super administrator.';
        }

        return DB::transaction(function () use ($target, $actor, $newRole, $newActive, $roleChanges, $statusChanges): ?string {
            // Lock the super administrators so two people cannot demote each other at the same moment.
            $superAdmins = User::query()
                ->where('role', UserRole::SuperAdmin->value)
                ->where('is_active', true)
                ->lockForUpdate()
                ->pluck('id');

            $losesSuperAdmin = $superAdmins->contains($target->id)
                && (($roleChanges && $newRole !== UserRole::SuperAdmin) || ($statusChanges && $newActive === false));

            if ($losesSuperAdmin && $superAdmins->count() <= 1) {
                return 'This is the last active super administrator. Promote another account first, so the system is never left without one.';
            }

            $fromRole = $this->roleOf($target);

            $target->forceFill(array_filter([
                'role' => $roleChanges ? $newRole?->value : null,
                'is_active' => $statusChanges ? $newActive : null,
            ], fn ($value): bool => $value !== null))->save();

            if ($roleChanges && $newRole !== null) {
                $this->audit->record('user.role_changed', $target, [
                    'from' => $fromRole->value,
                    'to' => $newRole->value,
                ], "Role of {$target->name} changed from {$fromRole->label()} to {$newRole->label()}.", $actor);
            }

            if ($statusChanges) {
                $this->audit->record(
                    $newActive ? 'user.activated' : 'user.deactivated',
                    $target,
                    [],
                    $newActive ? "Account of {$target->name} activated." : "Account of {$target->name} deactivated.",
                    $actor,
                );
            }

            return null;
        });
    }

    private function roleOf(User $user): UserRole
    {
        $role = $user->role;

        return $role instanceof UserRole ? $role : (UserRole::tryFrom((string) $role) ?? UserRole::Viewer);
    }

    /**
     * @return array{total: int, active: int, inactive: int, super_admins: int}
     */
    private function stats(): array
    {
        $total = User::query()->count();
        $active = User::query()->where('is_active', true)->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'super_admins' => User::query()->where('role', UserRole::SuperAdmin->value)->where('is_active', true)->count(),
        ];
    }
}