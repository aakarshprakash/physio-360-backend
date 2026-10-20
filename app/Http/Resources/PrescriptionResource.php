<?php

namespace App\Http\Resources;

use App\Models\Prescription;
use Illuminate\Http\Request;

/** @mixin Prescription */
class PrescriptionResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'branch_id' => $this->branch_id,
            'patient' => $this->whenLoaded('patient', fn () => ['id' => $this->patient->id, 'full_name' => $this->patient->full_name, 'mrn' => $this->patient->mrn]),
            'practitioner' => $this->whenLoaded('practitioner', fn () => ['id' => $this->practitioner->id, 'name' => $this->practitioner->name]),
            'assessment_id' => $this->assessment_id,
            'treatment_plan_id' => $this->treatment_plan_id,
            'issued_on' => $this->date($this->issued_on),
            'diagnosis' => $this->diagnosis,
            /** @var list<array{name: string, parameters: string|null, duration_minutes: int|null, sessions: int|null}> */
            'modalities' => $this->modalities ?? [],
            /** @var list<array{name: string, dosage: string|null, notes: string|null}> */
            'exercises' => $this->exercises ?? [],
            'advice' => $this->advice,
            'precautions' => $this->precautions,
            'review_on' => $this->date($this->review_on),
            'can_edit' => $request->user()?->id === $this->practitioner_id,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
