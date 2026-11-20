<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EndpointType;
use App\Http\Requests\IndexConnectedMembersRequest;
use App\Models\Office;
use App\Services\AuditLogger;
use App\Services\ConnectedMemberService;
use App\Services\OfficeConnectionService;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConnectedMemberController extends Controller
{
    private const EXPORT_LIMIT = 10000;

    public function __construct(
        private readonly ConnectedMemberService $service,
        private readonly OfficeConnectionService $offices,
        private readonly AuditLogger $audit,
    ) {}

    public function index(IndexConnectedMembersRequest $request): Response
    {
        $filters = $request->filters();

        $connections = $this->service->query($filters)
            ->orderByDesc('last_seen_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn ($session) => $this->service->present($session));

        if ($connections->currentPage() === 1) {
            $this->audit->record(
                action: 'office_network.members_viewed',
                description: 'Viewed the connected members list.',
                metadata: array_filter($filters, fn ($value) => $value !== null),
                actor: $request->user(),
            );
        }

        return Inertia::render('connected-members/index', [
            'connections' => $connections,
            'summary' => $this->service->summary(),
            'source' => $this->offices->sourceState(),
            'offices' => Office::query()->orderBy('name')->get(['id', 'name']),
            'typeOptions' => collect(EndpointType::cases())
                ->map(fn (EndpointType $type) => ['value' => $type->value, 'label' => $type->label()])
                ->values(),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'office' => (string) ($filters['office'] ?? ''),
                'status' => $filters['status'] ?? '',
                'type' => $filters['type'] ?? '',
            ],
            'can' => [
                'manage' => $request->user()->can('assignments.manage'),
                'export' => $request->user()->can('office-network.export'),
            ],
        ]);
    }

    public function export(IndexConnectedMembersRequest $request): StreamedResponse
    {
        $filters = $request->filters();

        $rows = $this->service->query($filters)
            ->orderByDesc('last_seen_at')
            ->limit(self::EXPORT_LIMIT)
            ->get();

        $this->audit->record(
            action: 'office_network.connections_exported',
            description: 'Exported the connected members list.',
            metadata: ['rows' => $rows->count(), 'filters' => array_filter($filters, fn ($value) => $value !== null)],
            actor: $request->user(),
        );

        $filename = 'connected-members-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads names with accents correctly

            fputcsv($out, [
                'Member', 'Office', 'Device', 'Device type', 'MAC address', 'IP address',
                'Status', 'Connected since', 'Last seen', 'Interface',
            ], ',', '"', '');

            foreach ($rows as $session) {
                $row = $this->service->present($session);

                fputcsv($out, array_map($this->safe(...), [
                    $row['member_name'] ?? ($row['identified'] ? 'Shared device' : 'Unidentified device'),
                    $row['office_name'],
                    $row['device_name'] ?? $row['hostname'],
                    $row['device_type'],
                    $row['mac_address'],
                    $row['ip_address'],
                    $row['status'],
                    $row['started_at'],
                    $row['last_seen_at'],
                    $row['interface_name'],
                ]), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A cell that starts with = + - @ would be run as a formula by spreadsheet programs. */
    private function safe(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}