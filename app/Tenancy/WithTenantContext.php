<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;

/**
 * Job middleware: queued jobs run in a fresh worker with no request, so the
 * tenant must travel in the payload. Jobs use the TenantAwareJob trait which
 * captures the tenant id at dispatch time and registers this middleware.
 */
class WithTenantContext
{
    public function __construct(private readonly int $tenantId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        return app(TenantContext::class)->run($tenant, fn () => $next($job));
    }
}
