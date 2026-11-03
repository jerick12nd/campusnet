<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TvDisplay;
use App\Services\TvDisplayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TvDisplayAdminController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('tv-displays/index', [
            'displays' => TvDisplay::query()->latest()->get()->map(fn (TvDisplay $display): array => [
                'id' => $display->id,
                'name' => $display->name,
                'status' => $display->status(),
                'paired_at' => $display->paired_at?->toIso8601String(),
                'last_seen_at' => $display->last_seen_at?->toIso8601String(),
            ])->all(),
            // Shown once, right after creation, then gone.
            'pairing' => $request->session()->pull('tv_pairing'),
        ]);
    }

    public function store(Request $request, TvDisplayService $displays): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        ['display' => $display, 'code' => $code] = $displays->create($data['name'], $request->user());

        return to_route('tv-displays.index')->with('tv_pairing', [
            'name' => $display->name,
            'code' => $code,
            'expires_at' => $display->pairing_expires_at->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, TvDisplay $display, TvDisplayService $displays): RedirectResponse
    {
        $displays->revoke($display, $request->user());

        return back()->with('success', "Display {$display->name} was revoked. It stops receiving data immediately.");
    }
}