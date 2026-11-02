<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexAuditLogRequest;
use App\Services\AuditLogListingService;
use App\Services\SettingsService;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(IndexAuditLogRequest $request, AuditLogListingService $listing, SettingsService $settings): Response
    {
        $filters = $request->filters();
        $timezone = (string) $settings->get('timezone');

        return Inertia::render('audit-logs/index', [
            ...$listing->list($filters, $timezone),
            'filters' => $filters,
            'timezone' => $timezone,
            'retention_days' => (int) config('campusnet.retention.audit_logs_days'),
        ]);
    }
}