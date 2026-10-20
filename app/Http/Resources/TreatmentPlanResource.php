<?php

namespace App\Http\Resources;

use App\Models\TreatmentPlan;
use Illuminate\Http\Request;

/** @mixin TreatmentPlan */
class TreatmentPlanResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient' => $this->whenLoaded('patient', fn () => ['id' => $this->patient->id, 'full_name' => $this->patient->full_name, 'mrn' => $this->patient->mrn]),
            'practitioner' => $this->whenLoaded('practitioner', fn () => ['id' => $this->practitioner->id, 'name' => $this->practitioner->name]),
            'title' => $this->title,
            'diagnosis' => $this->diagnosis,
            'goals' => $this->goals,
            'start_date' => $this->date($this->start_date),
            'duration_weeks' => $this->duration_weeks,
            'frequency_per_week' => $this->frequency_per_week,
            'current_week' => (int) $this->current_week,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'exercises_count' => $this->whenCounted('exercises'),
            'exercises' => PlanExerciseResource::collection($this->whenLoaded('exercises')),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
