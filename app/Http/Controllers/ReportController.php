<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Monitoring\MonitoringManager;
use App\Services\AuditLogger;
use App\Services\ReportService;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(ReportRequest $request, ReportService $reports, MonitoringManager $monitoring): Response
    {
        $period = $reports->period($request->fromDate(), $request->toDate());
        $type = $request->reportType();

        return Inertia::render('reports/index', [
            'report' => [
                'type' => $type,
                'types' => collect(ReportService::TYPES)
                    ->map(fn (array $definition, string $key): array => ['value' => $key, ...$definition])
                    ->values()
                    ->all(),
                'from_date' => $period['from_date'],
                'to_date' => $period['to_date'],
                'timezone' => $period['timezone'],
                'max_days' => ReportService::MAX_DAYS,
                'mode' => ['demo' => $monitoring->isDemo(), 'driver' => $monitoring->driverName()],
                'filename' => $reports->filename($type, $period),
                ...$reports->preview($type, $period),
            ],
            'can' => ['export' => (bool) $request->user()?->can('reports.export')],
        ]);
    }

    public function download(ReportRequest $request, ReportService $reports, AuditLogger $audit): StreamedResponse
    {
        $period = $reports->period($request->fromDate(), $request->toDate());
        $type = $request->reportType();
        $report = $reports->build($type, $period);

        $audit->record('report.exported', null, [
            'report' => $type,
            'from' => $period['from_date'],
            'to' => $period['to_date'],
            'rows' => count($report['rows']),
        ], 'Report exported: '.ReportService::TYPES[$type]['label'].'.', $request->user());

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            // The byte order mark makes Excel read the file as UTF-8, so names with accents stay intact.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $report['columns'], ',', '"', '', "\r\n");

            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map([ReportService::class, 'csvCell'], $row), ',', '"', '', "\r\n");
            }

            fclose($out);
        }, $reports->filename($type, $period), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}