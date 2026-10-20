<?php

namespace App\Tenancy;

use Illuminate\Auth\EloquentUserProvider;

/**
 * Session guards resolve the user before any tenant context exists, so identity
 * lookups bypass the tenant scope. The resolved identity then *defines* the context
 * (see SetTenantContext middleware); nothing else is loaded without it.
 */
class TenantlessEloquentUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}
