<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRangeRequest;
use App\Services\DashboardService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(DashboardRangeRequest $request, DashboardService $dashboard): Response
    {
        return Inertia::render('dashboard', [
            'dashboard' => $dashboard->build($request->range()),
        ]);
    }
}