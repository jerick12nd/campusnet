<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\TvDisplayService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets in a signed-in user with tv-display.view, or a paired display holding a valid token cookie.
 */
final class EnsureTvDisplayAccess
{
    public function __construct(private readonly TvDisplayService $displays) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->can('tv-display.view')) {
            return $next($request);
        }

        $cookie = $request->cookie(TvDisplayService::COOKIE);
        $display = is_string($cookie) && $cookie !== '' ? $this->displays->authenticate($cookie) : null;

        if ($display !== null) {
            $request->attributes->set('tv_display', $display);

            return $next($request);
        }

        // The wallboard polls with Accept: application/json and shows its reconnect state on 401.
        if ($request->expectsJson()) {
            return response()->json(['reason' => 'unauthorized'], 401);
        }

        // A person with no session and no display cookie goes to sign in; a revoked display sees a clear message.
        if ($request->user() === null && $cookie === null) {
            return redirect()->guest(route('login'));
        }

        abort(403, 'This display is not authorized. Ask a Super Administrator for a new pairing link.');
    }
}