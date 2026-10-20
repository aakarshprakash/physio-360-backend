<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use Auditable, BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['annual_quota' => 0, 'is_paid' => true, 'color' => '#3E8E76', 'is_active' => true];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['annual_quota' => 'float', 'is_paid' => 'boolean', 'is_active' => 'boolean'];
    }

    /** A quota of 0 means the type is not capped (e.g. loss of pay). */
    public function isCapped(): bool
    {
        return $this->annual_quota > 0;
    }
}
