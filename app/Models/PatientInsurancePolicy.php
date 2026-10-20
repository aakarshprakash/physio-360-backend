<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientInsurancePolicy extends Model
{
    use Auditable, BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['coverage_percent' => 100, 'is_primary' => true];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'policy_number' => 'encrypted',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'coverage_percent' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(InsurancePayer::class, 'insurance_payer_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
