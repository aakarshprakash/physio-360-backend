<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsurancePayer extends Model
{
    use Auditable, BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['is_active' => true, 'type' => 'insurer'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
