<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentPlanExercise extends Model
{
    use BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['sets' => 3, 'frequency' => 'Daily', 'sort_order' => 0];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['sets' => 'integer', 'reps' => 'integer', 'hold_seconds' => 'integer', 'sort_order' => 'integer'];
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ExerciseLog::class);
    }
}
