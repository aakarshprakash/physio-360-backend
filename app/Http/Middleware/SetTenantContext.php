<?php

namespace App\Http\Middleware;

use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Derives the tenant from the authenticated identity (staff user or patient)
 * and refuses suspended clinics / deactivated accounts. Must run after auth
 * and before route-model binding (see bootstrap/app.php priority).
 *
 * Usage: "tenant" (staff, web guard) or "tenant:patient".
 */
class SetTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        // Staff arrive via the session (web) or a mobile bearer token (sanctum).
        $identity = Auth::guard($guard)->user() ?? ($guard === 'web' ? Auth::guard('sanctum')->user() : null);

        if (! $identity instanceof User && ! $identity instanceof Patient) {
            abort(401);
        }

        $signOut = function () use ($guard, $identity) {
            if (Auth::guard($guard)->check()) {
                Auth::guard($guard)->logout();
            } elseif ($identity instanceof User) {
                $identity->currentAccessToken()?->delete();
            }
        };

        $tenant = Tenant::query()->find($identity->tenant_id);

        if (! $tenant || ! $tenant->isActive()) {
            $signOut();
            abort(response()->json(['message' => 'This clinic account is suspended. Please contact support.', 'code' => 'tenant_suspended'], 403));
        }

        if ($identity instanceof User && ! $identity->is_active) {
            $signOut();
            abort(response()->json(['message' => 'Your account has been deactivated.', 'code' => 'account_inactive'], 403));
        }

        if ($identity instanceof Patient && ! $identity->portal_enabled) {
            $signOut();
            abort(response()->json(['message' => 'Portal access is disabled for this account.', 'code' => 'portal_disabled'], 403));
        }

        $this->context->set($tenant);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
