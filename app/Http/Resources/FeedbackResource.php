<?php

namespace App\Http\Resources;

use App\Models\Feedback;
use Illuminate\Http\Request;

/** @mixin Feedback */
class FeedbackResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appointment_id' => $this->appointment_id,
            'patient' => $this->whenLoaded('patient', fn () => ['id' => $this->patient->id, 'full_name' => $this->patient->full_name]),
            'practitioner' => $this->whenLoaded('practitioner', fn () => ['id' => $this->practitioner->id, 'name' => $this->practitioner->name]),
            'rating' => $this->rating,
            'comment' => $this->comment,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
