<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use Auditable, BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['is_active' => true, 'tax_rate_bp' => 0, 'duration_minutes' => 45, 'price_minor' => 0];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price_minor' => 'integer',
            'tax_rate_bp' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }
}
