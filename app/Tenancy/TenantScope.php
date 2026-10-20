<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every Eloquent query on tenant-owned models to the current tenant.
 * Fails closed: with no tenant context the query matches nothing.
 *
 * Code that legitimately crosses tenants (platform console, auth providers,
 * scheduled fan-out) must opt out explicitly with withoutGlobalScope(TenantScope::class).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
