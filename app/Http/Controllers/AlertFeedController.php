<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AlertType;
use App\Models\NetworkAlert;
use App\Monitoring\MonitoringManager;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

/**
 * Small JSON feed for the notification bell in the top bar: the alerts that still need
 * attention (active warnings and criticals). It reuses DashboardService::alertQuery(), so
 * the same demo/real data rules apply as everywhere else.
 */
class AlertFeedController extends Controller
{
    public function index(DashboardService $dashboard, MonitoringManager $monitoring): JsonResponse
    {
        $source = $monitoring->driverName();

        $rows = $dashboard->alertQuery($source)
            ->where('status', 'active')
            ->whereIn('severity', ['warning', 'critical'])
            ->selectRaw('severity, COUNT(*) as aggregate')
            ->groupBy('severity')
            ->toBase()
            ->get();

        $count = static fn (string $severity): int => (int) $rows->where('severity', $severity)->sum('aggregate');

        $alerts = $dashboard->alertQuery($source)
            ->where('status', 'active')
            ->whereIn('severity', ['warning', 'critical'])
            ->with(['device' => fn ($query) => $query->select('id', 'name')])
            ->latest('triggered_at')
            ->limit(15)
            ->get()
            ->map(fn (NetworkAlert $alert): array => [
                'id' => $alert->id,
                'title' => $alert->title,
                'severity' => $alert->severity->value,
                'type' => $alert->type instanceof AlertType ? $alert->type->value : (string) $alert->type,
                'device' => $alert->device?->name,
                'triggered_at' => $alert->triggered_at->toIso8601String(),
            ])
            ->all();

        return response()
            ->json([
                'counts' => [
                    'critical' => $count('critical'),
                    'warning' => $count('warning'),
                    'total' => $count('critical') + $count('warning'),
                ],
                'alerts' => $alerts,
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}