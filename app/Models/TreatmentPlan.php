<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\TreatmentPlanStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentPlan extends Model
{
    use Auditable, BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'active', 'duration_weeks' => 6, 'frequency_per_week' => 2];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'status' => TreatmentPlanStatus::class,
            'start_date' => 'date',
            'duration_weeks' => 'integer',
            'frequency_per_week' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'practitioner_id');
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(TreatmentPlanExercise::class)->orderBy('sort_order')->orderBy('id');
    }

    /** 1-based current week, clamped to the plan duration. */
    protected function currentWeek(): Attribute
    {
        return Attribute::get(function () {
            $days = (int) $this->start_date->startOfDay()->diffInDays(now()->startOfDay(), false);
            if ($days < 0) {
                return 0;
            }

            return min($this->duration_weeks, intdiv($days, 7) + 1);
        });
    }
}
