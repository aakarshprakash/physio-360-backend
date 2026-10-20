<?php

namespace App\Http\Resources;

use App\Models\ClinicalNote;
use Illuminate\Http\Request;

/** @mixin ClinicalNote */
class ClinicalNoteResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'appointment_id' => $this->appointment_id,
            'author' => $this->whenLoaded('author', fn () => ['id' => $this->author->id, 'name' => $this->author->name]),
            'subjective' => $this->subjective,
            'objective' => $this->objective,
            'assessment' => $this->assessment,
            'plan' => $this->plan,
            'pain_score' => $this->pain_score,
            'status' => $this->status->value,
            'signed_at' => $this->iso($this->signed_at),
            'content_hash' => $this->content_hash,
            'addenda' => $this->whenLoaded('addenda', fn () => $this->addenda->map(fn ($a) => [
                'id' => $a->id,
                'body' => $a->body,
                'author' => $a->author?->name,
                'created_at' => $this->iso($a->created_at),
            ])),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
