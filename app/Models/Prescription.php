<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Printable physiotherapy prescription (diagnosis, modalities, home exercises, advice). */
class Prescription extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'number', 'practitioner_id'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'review_on' => 'date',
            'diagnosis' => 'encrypted',
            'modalities' => 'encrypted:array',
            'exercises' => 'encrypted:array',
            'advice' => 'encrypted',
            'precautions' => 'encrypted',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'practitioner_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function treatmentPlan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class);
    }
}
