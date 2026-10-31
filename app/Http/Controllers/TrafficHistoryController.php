<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TrafficHistoryRequest;
use App\Services\TrafficHistoryService;
use Inertia\Inertia;
use Inertia\Response;

class TrafficHistoryController extends Controller
{
    public function index(TrafficHistoryRequest $request, TrafficHistoryService $history): Response
    {
        return Inertia::render('traffic-history', [
            'traffic' => $history->build(
                $request->range(),
                $request->fromDate(),
                $request->toDate(),
                $request->interfaceId(),
            ),
        ]);
    }
}