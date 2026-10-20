<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            $context = app(TenantContext::class);

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $context->require()->id);
            } elseif ($context->has() && (int) $model->getAttribute('tenant_id') !== $context->id()) {
                throw new CrossTenantWrite(static::class);
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('tenant_id')) {
                throw new CrossTenantWrite(static::class);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
