<?php

namespace App\Http\Controllers;

use App\Models\ScanHost;
use App\Models\ScanRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class NetworkController extends Controller
{
    /** A link is "stale" when the ping scheduler has not stored a check for this long. */
    private const STALE_AFTER_SECONDS = 180;

    /** Chart resolution: one point per 10 minutes. */
    private const BUCKET_SECONDS = 600;

    public function index()
    {
        return Inertia::render('Network/Index', [
            'offices' => $this->offices(),
            ...$this->linkData(),
        ]);
    }

    /**
     * @return array{links: list<array<string, mixed>>, history: array<string, list<array<string, mixed>>>}
     */
    private function linkData(): array
    {
        $checks = DB::table('link_checks')
            ->where('checked_at', '>=', now()->subDay())
            ->orderBy('checked_at')
            ->get()
            ->groupBy('target');

        $links = $checks->map(function ($rows, $target) {
            $latest = $rows->last();
            $checkedAt = Carbon::parse($latest->checked_at);
            $latencies = $rows->pluck('avg_ms')->filter(fn ($v) => $v !== null);

            return [
                'target'     => (string) $target,
                'label'      => $latest->label,
                'isUp'       => (bool) $latest->is_up,
                'stale'      => $checkedAt->diffInSeconds(now()) > self::STALE_AFTER_SECONDS,
                'lossPercent'=> (int) $latest->loss_percent,
                'avgMs'      => $latest->avg_ms !== null ? (int) $latest->avg_ms : null,
                'maxMs'      => $latest->max_ms !== null ? (int) $latest->max_ms : null,
                'checkedAt'  => $checkedAt->toIso8601String(),
                'uptime24h'  => round($rows->where('is_up', 1)->count() / max(1, $rows->count()) * 100, 1),
                'avgMs24h'   => $latencies->isEmpty() ? null : (int) round($latencies->avg()),
                'peakMs24h'  => $rows->pluck('max_ms')->filter(fn ($v) => $v !== null)->max(),
                'checks24h'  => $rows->count(),
            ];
        })
            ->sortBy(fn (array $link) => $link['target'] === '8.8.8.8' ? 1 : 0)
            ->values()
            ->all();

        $history = $checks->map(function ($rows) {
            return $rows
                ->groupBy(fn ($r) => intdiv(Carbon::parse($r->checked_at)->getTimestamp(), self::BUCKET_SECONDS))
                ->map(function ($bucket, $slot) {
                    $avg = $bucket->pluck('avg_ms')->filter(fn ($v) => $v !== null);
                    $max = $bucket->pluck('max_ms')->filter(fn ($v) => $v !== null);

                    return [
                        't'    => Carbon::createFromTimestamp($slot * self::BUCKET_SECONDS)->toIso8601String(),
                        'avg'  => $avg->isEmpty() ? null : (int) round($avg->avg()),
                        'max'  => $max->isEmpty() ? null : (int) $max->max(),
                        'loss' => (int) round($bucket->avg('loss_percent')),
                    ];
                })
                ->values()
                ->all();
        })->all();

        return ['links' => $links, 'history' => $history];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function offices(): array
    {
        // Latest scan of each office
        $latest = ScanRun::query()
            ->whereIn('id', ScanRun::selectRaw('MAX(id)')->groupBy('office'))
            ->get();

        // Peak and average of the last 24 hours
        $stats = ScanRun::query()
            ->where('scanned_at', '>=', now()->subDay())
            ->selectRaw('office, MAX(hosts_up) as peak, ROUND(AVG(hosts_up)) as average')
            ->groupBy('office')
            ->get()
            ->keyBy('office');

        // Devices with a random MAC in the latest scan (mostly phones)
        $randomCounts = ScanHost::query()
            ->whereIn('scan_run_id', $latest->pluck('id'))
            ->where('random_mac', true)
            ->selectRaw('scan_run_id, COUNT(*) as total')
            ->groupBy('scan_run_id')
            ->pluck('total', 'scan_run_id');

        return $latest->map(fn (ScanRun $run) => [
            'office'     => $run->office,
            'cidr'       => $run->cidr,
            'online'     => $run->hosts_up,
            'randomMac'  => (int) ($randomCounts[$run->id] ?? 0),
            'peak24h'    => (int) ($stats[$run->office]->peak ?? 0),
            'average24h' => (int) ($stats[$run->office]->average ?? 0),
            'scannedAt'  => $run->scanned_at->toIso8601String(),
        ])->values()->all();
    }
}