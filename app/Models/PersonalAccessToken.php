<?php

namespace App\Models;

use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Mobile app tokens. Like the session guards' TenantlessEloquentUserProvider,
 * the token's owner is resolved before any tenant context exists; the owner
 * then defines the context (SetTenantContext).
 */
class PersonalAccessToken extends SanctumToken
{
    protected $table = 'personal_access_tokens';

    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable')->withoutGlobalScope(TenantScope::class);
    }
}
