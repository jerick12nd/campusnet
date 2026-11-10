<?php

namespace App\Http\Controllers;

use App\Models\ScanHost;
use App\Models\ScanRun;
use Inertia\Inertia;

class NetworkController extends Controller
{
    public function index()
    {
        // Pinakabag-o nga scan kada office
        $latest = ScanRun::query()
            ->whereIn('id', ScanRun::selectRaw('MAX(id)')->groupBy('office'))
            ->get();

        // Peak ug average sa miaging 24 oras
        $stats = ScanRun::query()
            ->where('scanned_at', '>=', now()->subDay())
            ->selectRaw('office, MAX(hosts_up) as peak, ROUND(AVG(hosts_up)) as average')
            ->groupBy('office')
            ->get()
            ->keyBy('office');

        // Pila ka device sa pinakabag-o nga scan ang naay random MAC (phones ra kasagaran)
        $randomCounts = ScanHost::query()
            ->whereIn('scan_run_id', $latest->pluck('id'))
            ->where('random_mac', true)
            ->selectRaw('scan_run_id, COUNT(*) as total')
            ->groupBy('scan_run_id')
            ->pluck('total', 'scan_run_id');

        $offices = $latest->map(fn (ScanRun $run) => [
            'office'      => $run->office,
            'cidr'        => $run->cidr,
            'online'      => $run->hosts_up,
            'randomMac'   => (int) ($randomCounts[$run->id] ?? 0),
            'peak24h'     => (int) ($stats[$run->office]->peak ?? 0),
            'average24h'  => (int) ($stats[$run->office]->average ?? 0),
            'scannedAt'   => $run->scanned_at->toIso8601String(),
        ])->values();

        return Inertia::render('Network/Index', ['offices' => $offices]);
    }
}