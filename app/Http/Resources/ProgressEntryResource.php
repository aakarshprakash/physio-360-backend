<?php

namespace App\Http\Resources;

use App\Models\ProgressEntry;
use Illuminate\Http\Request;

/** @mixin ProgressEntry */
class ProgressEntryResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'treatment_plan_id' => $this->treatment_plan_id,
            'recorded_on' => $this->date($this->recorded_on),
            'pain_score' => $this->pain_score,
            'function_score' => $this->function_score,
            'measure' => $this->measure,
            'value' => $this->value !== null ? (float) $this->value : null,
            'unit' => $this->unit,
            'notes' => $this->notes,
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->name),
        ];
    }
}
