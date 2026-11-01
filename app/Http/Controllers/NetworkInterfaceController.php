<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexInterfaceRequest;
use App\Http\Requests\UpdateInterfaceRequest;
use App\Models\NetworkInterface;
use App\Monitoring\MonitoringManager;
use App\Services\AuditLogger;
use App\Services\InterfaceListingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NetworkInterfaceController extends Controller
{
    public function index(IndexInterfaceRequest $request, InterfaceListingService $listing): Response
    {
        $filters = $request->filters();
        $data = $listing->list($filters);

        return Inertia::render('interfaces/index', [
            'interfaces' => $data['interfaces'],
            'filters' => $filters,
            'stats' => $data['stats'],
            'devices' => $data['devices'],
            'options' => ['roles' => $data['roles']],
            'can' => ['manage' => (bool) $request->user()?->can('interfaces.manage')],
        ]);
    }

    public function update(
        UpdateInterfaceRequest $request,
        NetworkInterface $interface,
        AuditLogger $audit,
        MonitoringManager $monitoring,
    ): RedirectResponse {
        $device = $interface->device; // null when the device was archived

        // An archived device, or a demo device while real monitoring is active, does not exist for the user.
        abort_if($device === null || ($device->is_demo && ! $monitoring->isDemo()), 404);

        $interface->fill($request->validated());

        $changes = [];

        foreach ($interface->getDirty() as $field => $value) {
            $changes[$field] = [
                'from' => $this->plain($field, $interface->getRawOriginal($field)),
                'to' => $this->plain($field, $value),
            ];
        }

        if ($changes === []) {
            return back();
        }

        $interface->save();

        $audit->record('interface.updated', $interface, [
            'device' => $device->name,
            'interface' => $interface->name,
            'changes' => $changes,
        ], "Interface {$interface->name} on {$device->name} updated.", $request->user());

        return back()->with('success', "{$interface->name} on {$device->name} was updated.");
    }

    private function plain(string $field, mixed $value): mixed
    {
        return $field === 'is_monitored' ? (bool) $value : $value;
    }
}