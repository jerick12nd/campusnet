<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ObservationType;
use App\Http\Requests\WebsiteActivityRequest;
use App\Models\Member;
use App\Models\Office;
use App\Services\AuditLogger;
use App\Services\WebsiteActivityService;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WebsiteActivityController extends Controller
{
    private const EXPORT_LIMIT = 10000;

    public function __construct(
        private readonly WebsiteActivityService $service,
        private readonly AuditLogger $audit,
    ) {}

    public function index(WebsiteActivityRequest $request): Response
    {
        $user = $request->user();
        $memberLevel = $user->can('website-activity.member-detail');
        $filters = $this->service->withDefaultRange($request->filters());

        $records = $this->service->query($filters)
            ->with($this->service->relations($memberLevel))
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn ($record) => $this->service->present($record, $memberLevel));

        if ($records->currentPage() === 1) {
            $this->audit->record(
                action: 'office_network.website_activity_viewed',
                description: 'Viewed website activity.',
                metadata: [...$this->auditFilters($filters), 'member_level' => $memberLevel],
                actor: $user,
            );
        }

        return Inertia::render('website-activity/index', [
            'records' => $records,
            'summary' => $this->service->summary($filters),
            'topDomains' => $this->service->topDomains($filters),
            'byOffice' => $this->service->byOffice($filters),
            'trend' => $this->service->trend($filters),
            'sources' => $this->service->sources(),
            'offices' => Office::query()->orderBy('name')->get(['id', 'name']),
            'members' => $memberLevel ? Member::query()->orderBy('full_name')->get(['id', 'full_name']) : [],
            'typeOptions' => collect(ObservationType::cases())
                ->map(fn (ObservationType $type) => ['value' => $type->value, 'label' => $type->label()])
                ->values(),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'office' => (string) ($filters['office'] ?? ''),
                'type' => $filters['type'] ?? '',
                'member' => (string) ($filters['member'] ?? ''),
                'from' => $filters['from'],
                'to' => $filters['to'],
            ],
            'defaultRange' => $this->service->defaultRange(),
            'maxRangeDays' => (int) config('office_network.max_report_days'),
            'can' => [
                'memberLevel' => $memberLevel,
                'export' => $user->can('office-network.export'),
                'manageSources' => $user->can('activity-sources.manage'),
            ],
        ]);
    }

    public function export(WebsiteActivityRequest $request): StreamedResponse
    {
        $user = $request->user();
        $memberLevel = $user->can('website-activity.member-detail');
        $filters = $this->service->withDefaultRange($request->filters());

        $rows = $this->service->query($filters)
            ->with($this->service->relations($memberLevel))
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->limit(self::EXPORT_LIMIT)
            ->get()
            ->map(fn ($record) => $this->service->present($record, $memberLevel));

        $this->audit->record(
            action: 'office_network.website_activity_exported',
            description: 'Exported website activity.',
            metadata: [...$this->auditFilters($filters), 'rows' => $rows->count(), 'member_level' => $memberLevel],
            actor: $user,
        );

        $filename = 'website-activity-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $memberLevel): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            $header = ['Domain', 'Base domain', 'Type', 'Source', 'Office', 'Hits', 'Observed at'];

            if ($memberLevel) {
                array_push($header, 'Member', 'Device', 'IP address');
            }

            fputcsv($out, $header, ',', '"', '');

            foreach ($rows as $row) {
                $line = [
                    $row['domain'], $row['base_domain'], $row['observation_label'], $row['source_name'],
                    $row['office_name'], $row['hits'], $row['observed_at'],
                ];

                if ($memberLevel) {
                    array_push($line, $row['member_name'], $row['device_name'], $row['client_ip']);
                }

                fputcsv($out, array_map($this->safe(...), $line), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function auditFilters(array $filters): array
    {
        return array_filter($filters, fn ($value) => $value !== null);
    }

    /** A cell that starts with = + - @ would be run as a formula by spreadsheet programs. */
    private function safe(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}