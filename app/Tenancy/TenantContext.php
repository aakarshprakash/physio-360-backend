<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * The single source of truth for "which clinic is this request/job acting for".
 *
 * Set by middleware (from the authenticated staff user or patient), by the
 * WithTenantContext job middleware (from the job payload), or by run() in
 * console commands. The global TenantScope fails closed when it is unset.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant?->id);
    }

    public function clear(): void
    {
        $this->set(null);
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function require(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantContext;
    }

    /**
     * Execute a callback acting as $tenant, restoring the previous context afterwards.
     *
     * @template T
     *
     * @param  Closure(Tenant): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback($tenant);
        } finally {
            $this->set($previous);
        }
    }

    /** Namespaced cache key so cached values can never leak across tenants. */
    public function cacheKey(string $key): string
    {
        return 't:'.$this->require()->id.':'.$key;
    }
}
