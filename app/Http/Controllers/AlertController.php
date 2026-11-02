<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexAlertRequest;
use App\Http\Requests\ResolveAlertRequest;
use App\Models\NetworkAlert;
use App\Models\User;
use App\Monitoring\MonitoringManager;
use App\Services\AlertListingService;
use App\Services\AlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(IndexAlertRequest $request, AlertListingService $listing): Response
    {
        $filters = $request->filters();
        $data = $listing->list($filters);

        return Inertia::render('alerts/index', [
            'alerts' => $data['alerts'],
            'filters' => $filters,
            'stats' => $data['stats'],
            'devices' => $data['devices'],
            'options' => ['types' => $data['types'], 'severities' => $data['severities']],
            'can' => ['manage' => (bool) $request->user()?->can('alerts.manage')],
        ]);
    }

    public function acknowledge(Request $request, NetworkAlert $alert, AlertService $alerts, MonitoringManager $monitoring): RedirectResponse
    {
        $this->ensureVisible($alert, $monitoring);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! $alerts->acknowledge($alert, $user)) {
            return back()->with('error', "This alert can no longer be acknowledged: it is already {$alert->status->value}.");
        }

        return back()->with('success', 'Alert acknowledged.');
    }

    public function resolve(ResolveAlertRequest $request, NetworkAlert $alert, AlertService $alerts, MonitoringManager $monitoring): RedirectResponse
    {
        $this->ensureVisible($alert, $monitoring);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! $alerts->resolve($alert, $user, $request->note())) {
            return back()->with('error', 'This alert was already resolved.');
        }

        return back()->with('success', 'Alert resolved.');
    }

    /**
     * An alert of an archived device, or of a demo device while a real driver is active,
     * does not exist for the user, exactly as in the list.
     */
    private function ensureVisible(NetworkAlert $alert, MonitoringManager $monitoring): void
    {
        if ($alert->monitored_device_id === null) {
            return;
        }

        $device = $alert->device; // null when the device was archived

        abort_if($device === null || ($device->is_demo && ! $monitoring->isDemo()), 404);
    }
}