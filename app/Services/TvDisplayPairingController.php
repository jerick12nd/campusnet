<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TvDisplayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TvDisplayPairingController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('tv-display-pair');
    }

    public function redeem(Request $request, TvDisplayService $displays): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        $paired = $displays->redeem($data['code'], $request->ip());

        if ($paired === null) {
            throw ValidationException::withMessages([
                'code' => 'That code is not valid or has expired. Ask a Super Administrator for a new one.',
            ]);
        }

        return redirect()->route('tv-display.show')
            ->withCookie(cookie(
                TvDisplayService::COOKIE,
                $paired['token'],
                TvDisplayService::COOKIE_MINUTES,
                '/',
                null,
                $request->isSecure(),
                true,   // HttpOnly: page scripts cannot read it
                false,
                'lax',
            ));
    }
}