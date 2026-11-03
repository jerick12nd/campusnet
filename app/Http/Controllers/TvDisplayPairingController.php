<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TvDisplayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TvDisplayPairingController extends Controller
{
    private const MAX_WRONG_CODES = 10;

    private const LOCKOUT_SECONDS = 900;

    public function show(): Response
    {
        return Inertia::render('auth/tv-display');
    }

    public function redeem(Request $request, TvDisplayService $displays): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        // Only wrong codes count: 10 misses from one address block it for 15 minutes.
        $key = 'tv-pair:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_WRONG_CODES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            throw ValidationException::withMessages([
                'code' => "Too many wrong codes. Try again in {$minutes} minute(s).",
            ]);
        }

        $paired = $displays->redeem($data['code'], $request->ip());

        if ($paired === null) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            throw ValidationException::withMessages([
                'code' => 'That code is not valid or has expired. Ask a Super Administrator for a new one.',
            ]);
        }

        RateLimiter::clear($key);

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