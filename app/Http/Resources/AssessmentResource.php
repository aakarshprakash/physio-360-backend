<?php

namespace App\Http\Resources;

use App\Models\Assessment;
use Illuminate\Http\Request;

/** @mixin Assessment */
class AssessmentResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'appointment_id' => $this->appointment_id,
            'practitioner' => $this->whenLoaded('practitioner', fn () => ['id' => $this->practitioner->id, 'name' => $this->practitioner->name]),
            'assessed_at' => $this->iso($this->assessed_at),
            'chief_complaint' => $this->chief_complaint,
            'history' => $this->history,
            'pain_score' => $this->pain_score,
            'pain_location' => $this->pain_location,
            'findings' => $this->findings ?? (object) [],
            'diagnosis' => $this->diagnosis,
            'goals' => $this->goals,
            'status' => $this->status->value,
            'signed_at' => $this->iso($this->signed_at),
        ];
    }
}
