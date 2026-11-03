<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TvDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TvDisplayController extends Controller
{
    public function show(Request $request, TvDashboardService $tv): Response
    {
        return Inertia::render('tv-display', [
            // The first paint already has real data, so the page does not need an extra request.
            'initial' => $tv->build(),
            // Only a signed-in user gets the control overlay. A paired TV is display-only.
            'can_control' => (bool) $request->user()?->can('tv-display.view'),
        ]);
    }

    public function data(TvDashboardService $tv): JsonResponse
    {
        return response()->json($tv->build())
            ->header('Cache-Control', 'no-store, private');
    }
}