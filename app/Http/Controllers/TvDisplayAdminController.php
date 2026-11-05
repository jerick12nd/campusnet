<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TvDisplay;
use App\Services\TvDisplayService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;

class TvDisplayAdminController extends Controller
{
    public function index(Request $request, TvDisplayService $displays): Response
    {
        // First visit and nothing exists yet: create a default display with a reusable code,
        // so the code is already waiting here. Revoked displays still count, so this never recreates one you removed.
        if (TvDisplay::query()->doesntExist()) {
            $displays->create('Default TV Display', $request->user(), true);
        }

        return Inertia::render('settings/tv-display', [
            'displays' => TvDisplay::query()->latest()->get()->map(fn (TvDisplay $display): array => [
                'id' => $display->id,
                'name' => $display->name,
                'status' => $display->status(),
                'reusable' => $display->is_reusable,
                // Only a Super Administrator reaches this page, and only reusable codes can be shown again.
                'code' => $this->visibleCode($display),
                'paired_at' => $display->paired_at?->toIso8601String(),
                'last_seen_at' => $display->last_seen_at?->toIso8601String(),
            ])->all(),
            // Shown once, right after creation, then gone.
            'pairing' => $request->session()->pull('tv_pairing'),
        ]);
    }

    public function store(Request $request, TvDisplayService $displays): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'reusable' => ['sometimes', 'boolean'],
        ]);

        $reusable = $request->boolean('reusable');

        ['display' => $display, 'code' => $code] = $displays->create($data['name'], $request->user(), $reusable);

        return to_route('tv-displays.index')->with('tv_pairing', [
            'name' => $display->name,
            'code' => $code,
            'reusable' => $reusable,
            'expires_at' => $display->pairing_expires_at?->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, TvDisplay $display, TvDisplayService $displays): RedirectResponse
    {
        $displays->revoke($display, $request->user());

        return back()->with('success', "Display {$display->name} was revoked. It stops receiving data immediately.");
    }

    private function visibleCode(TvDisplay $display): ?string
    {
        if (! $display->is_reusable || $display->revoked_at !== null || $display->pairing_code === null) {
            return null;
        }

        try {
            return Crypt::decryptString($display->pairing_code);
        } catch (DecryptException) {
            return null;
        }
    }
}