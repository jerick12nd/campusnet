<?php

namespace App\Http\Middleware;

use App\Monitoring\MonitoringManager;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],

            // CampusNet: what the signed-in role may do (drives the sidebar) ...
            'access' => fn () => [
                'role' => $user?->role?->value,
                'role_label' => $user?->role?->label(),
                'permissions' => $user?->permissions() ?? [],
            ],

            // ... and the monitoring mode, so every page can show the DEMO banner.
            'campusnet' => fn () => [
                'demo_mode' => app(MonitoringManager::class)->isDemo(),
                'driver' => app(MonitoringManager::class)->driverName(),
                'campus_name' => app(SettingsService::class)->get('campus_name'),
                'timezone' => app(SettingsService::class)->get('timezone'),
                'flash' => [
                    'success' => $request->session()->get('success'),
                    'error' => $request->session()->get('error'),
                    'connection_test' => $request->session()->get('connection_test'),
                ],
            ],
        ];
    }
}