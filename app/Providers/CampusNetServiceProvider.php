<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CampusNetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        $this->registerPermissionGates();
        $this->registerAuditListeners();
    }

    /**
     * One gate per permission string. Authorize on the backend with
     * `->middleware('can:devices.manage')` or `$this->authorize('devices.manage')`.
     * Hiding a button in React is never the only protection.
     */
    private function registerPermissionGates(): void
    {
        foreach (UserRole::allPermissions() as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermission($permission));
        }
    }

    private function registerAuditListeners(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            $user = $event->user;

            if (! $user instanceof User) {
                return;
            }

            $user->forceFill(['last_login_at' => now()])->saveQuietly();

            app(AuditLogger::class)->record(
                action: 'auth.login',
                subject: $user,
                description: 'User signed in.',
                actor: $user,
            );
        });

        Event::listen(Logout::class, function (Logout $event): void {
            $user = $event->user;

            if (! $user instanceof User) {
                return;
            }

            app(AuditLogger::class)->record(
                action: 'auth.logout',
                subject: $user,
                description: 'User signed out.',
                actor: $user,
            );
        });

        Event::listen(Failed::class, function (Failed $event): void {
            // Only the attempted e-mail is recorded. The password is never read here.
            app(AuditLogger::class)->record(
                action: 'auth.login_failed',
                subject: $event->user instanceof User ? $event->user : null,
                metadata: ['email' => $event->credentials['email'] ?? null],
                description: 'Failed sign-in attempt.',
            );
        });
    }
}