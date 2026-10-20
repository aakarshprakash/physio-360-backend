<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\PayrollStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use Auditable, BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'draft', 'total_gross_minor' => 0, 'total_deductions_minor' => 0, 'total_net_minor' => 0];

    protected $guarded = ['id', 'tenant_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => PayrollStatus::class,
            'working_days' => 'integer',
            'total_gross_minor' => 'integer',
            'total_deductions_minor' => 'integer',
            'total_net_minor' => 'integer',
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === PayrollStatus::Draft;
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }
}
