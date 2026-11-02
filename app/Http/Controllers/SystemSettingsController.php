<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Models\User;
use App\Monitoring\MonitoringManager;
use App\Services\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingsController extends Controller
{
    public function edit(Request $request, SystemSettingsService $settings, MonitoringManager $monitoring): Response
    {
        return Inertia::render('system-settings/edit', [
            'settings' => $settings->current(),
            'defaults' => $settings->defaults(),
            'retention' => [
                'traffic_samples_days' => (int) config('campusnet.retention.traffic_samples_days'),
                'audit_logs_days' => (int) config('campusnet.retention.audit_logs_days'),
            ],
            'driver' => ['name' => $monitoring->driverName(), 'demo' => $monitoring->isDemo()],
            'timezones' => \DateTimeZone::listIdentifiers(),
            'can' => ['manage' => (bool) $request->user()?->can('settings.manage')],
        ]);
    }

    public function update(UpdateSystemSettingsRequest $request, SystemSettingsService $settings): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $changes = $settings->update($request->validated(), $user);

        return back()->with(
            'success',
            $changes === [] ? 'Nothing changed.' : 'Settings saved: '.implode(', ', array_keys($changes)).'.',
        );
    }
}