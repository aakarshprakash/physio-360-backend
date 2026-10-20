<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle timeout is SESSION_LIFETIME (sliding). This adds an absolute cap
 * (AUTH_ABSOLUTE_TIMEOUT minutes since login) after which the user must
 * sign in again even if continuously active.
 */
class EnforceAbsoluteSessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $authenticatedAt = $request->session()->get('authenticated_at');
        $limit = (int) config('auth.absolute_timeout') * 60;

        if ($authenticatedAt && (time() - (int) $authenticatedAt) > $limit) {
            foreach (['web', 'patient', 'platform'] as $guard) {
                auth()->guard($guard)->logout();
            }
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Your session has expired. Please sign in again.', 'code' => 'session_expired'], 401);
        }

        return $next($request);
    }
}
