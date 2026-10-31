<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an account that was deactivated while signed in.
 * Without this, deactivating a user would not take effect until their
 * session expired.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->header('X-Inertia')) {
                return response()->json(['message' => 'This account has been deactivated.'], 403);
            }

            return redirect()->route('login')->withErrors([
                'email' => 'This account has been deactivated. Contact the ICT office.',
            ]);
        }

        return $next($request);
    }
}