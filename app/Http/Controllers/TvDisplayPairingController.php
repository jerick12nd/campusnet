<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TvDisplayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TvDisplayPairingController extends Controller
{
    public function redeem(Request $request, string $code, TvDisplayService $displays): RedirectResponse
    {
        $paired = $displays->redeem($code, $request->ip());

        if ($paired === null) {
            abort(403, 'This pairing link is invalid, expired or already used. Ask a Super Administrator for a new one.');
        }

        // Redirecting right away removes the code from the address bar and the history.
        return redirect()->route('tv-display.show')
            ->header('Referrer-Policy', 'no-referrer')
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