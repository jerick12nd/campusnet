<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BandwidthAnalyticsRequest;
use App\Services\BandwidthAnalyticsService;
use Inertia\Inertia;
use Inertia\Response;

class BandwidthAnalyticsController extends Controller
{
    public function index(BandwidthAnalyticsRequest $request, BandwidthAnalyticsService $analytics): Response
    {
        return Inertia::render('bandwidth-analytics', [
            'analytics' => $analytics->build($request->range()),
        ]);
    }
}