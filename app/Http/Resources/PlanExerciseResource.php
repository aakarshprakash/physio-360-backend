<?php

namespace App\Http\Resources;

use App\Models\TreatmentPlanExercise;
use Illuminate\Http\Request;

/** @mixin TreatmentPlanExercise */
class PlanExerciseResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exercise' => new ExerciseResource($this->whenLoaded('exercise')),
            'sets' => $this->sets,
            'reps' => $this->reps,
            'hold_seconds' => $this->hold_seconds,
            'frequency' => $this->frequency,
            'notes' => $this->notes,
            'sort_order' => $this->sort_order,
            'done_today' => $this->when(isset($this->done_today), fn () => (bool) $this->done_today),
            'completed_days' => $this->when(isset($this->logs_count), fn () => (int) $this->logs_count),
        ];
    }
}
