<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ActivitySourceRequest;
use App\Models\ActivityDataSource;
use App\Models\MonitoredDevice;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ActivitySourceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('activity-sources/index', [
            'sources' => ActivityDataSource::query()
                ->with(['monitoredDevice' => fn ($q) => $q->select('id', 'name')])
                ->orderBy('name')
                ->get()
                ->map(fn (ActivityDataSource $source) => [
                    'id' => $source->id,
                    'name' => $source->name,
                    'type' => $source->type,
                    'description' => $source->description,
                    'is_enabled' => $source->is_enabled,
                    'retention_days' => $source->retention_days,
                    'monitored_device_id' => $source->monitored_device_id,
                    'device_name' => $source->monitoredDevice?->name,
                    'last_run_at' => $source->last_run_at?->toIso8601String(),
                    'last_status' => $source->last_status,
                    'last_error' => $source->last_error,
                ])
                ->values(),
            'devices' => MonitoredDevice::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(ActivitySourceRequest $request, ActivityDataSource $activitySource): RedirectResponse
    {
        $activitySource->fill($request->validated());
        $changes = $activitySource->getDirty();

        if ($changes === []) {
            return back()->with('success', 'Nothing changed.');
        }

        $activitySource->save();

        $this->audit->record(
            action: 'activity_source.updated',
            subject: $activitySource,
            description: "Data source {$activitySource->name} updated.",
            metadata: [
                'changed' => array_keys($changes),
                'is_enabled' => $activitySource->is_enabled,
                'retention_days' => $activitySource->retention_days,
            ],
            actor: $request->user(),
        );

        return back()->with('success', "{$activitySource->name} updated.");
    }
}