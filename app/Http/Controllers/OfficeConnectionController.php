<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Office;
use App\Services\AuditLogger;
use App\Services\OfficeConnectionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OfficeConnectionController extends Controller
{
    public function __construct(
        private readonly OfficeConnectionService $service,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(OfficeConnectionService::STATUSES)],
        ]);

        $overview = $this->service->overview($filters['search'] ?? null, $filters['status'] ?? null);

        return Inertia::render('office-connections/index', [
            ...$overview,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'can' => [
                'manage' => $request->user()->can('offices.manage'),
                'members' => $request->user()->can('members.view'),
            ],
        ]);
    }

    public function show(Request $request, Office $office): Response
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(['online', 'history', 'all'])],
        ]);
        $state = $filters['state'] ?? 'online';

        $overview = $this->service->overview(officeId: $office->id);
        $canSeeMembers = $request->user()->can('members.view');
        $connections = null;

        // Member-level rows (names, MAC, IP) need members.view. Everyone else sees counts only.
        if ($canSeeMembers) {
            $connections = $this->service->connections($office, $state)
                ->orderByDesc('last_seen_at')
                ->paginate(15)
                ->withQueryString()
                ->through(fn ($session) => $this->service->present($session));

            if ($connections->currentPage() === 1) {
                $this->audit->record(
                    action: 'office_network.office_viewed',
                    subject: $office,
                    description: "Viewed member-level connections of {$office->name}.",
                    metadata: ['state' => $state],
                    actor: $request->user(),
                );
            }
        }

        return Inertia::render('office-connections/show', [
            'office' => $overview['offices'][0],
            'source' => $overview['source'],
            'connections' => $connections,
            'filters' => ['state' => $state],
            'can' => ['members' => $canSeeMembers],
        ]);
    }
}