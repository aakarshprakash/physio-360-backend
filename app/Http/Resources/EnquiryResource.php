<?php

namespace App\Http\Resources;

use App\Models\Enquiry;
use Illuminate\Http\Request;

/**
 * @mixin Enquiry
 */
class EnquiryResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'source' => $this->source,
            'interest' => $this->interest,
            'service' => $this->whenLoaded('service', fn () => $this->service ? ['id' => $this->service->id, 'name' => $this->service->name] : null),
            'message' => $this->message,
            'preferred_contact_time' => $this->preferred_contact_time,
            'status' => $this->status->value,
            'lost_reason' => $this->lost_reason,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'next_follow_up_at' => $this->iso($this->next_follow_up_at),
            'is_overdue' => $this->status->isOpen() && $this->next_follow_up_at !== null && $this->next_follow_up_at->isPast(),
            'last_contacted_at' => $this->iso($this->last_contacted_at),
            'converted_patient' => $this->whenLoaded('convertedPatient', fn () => $this->convertedPatient ? [
                'id' => $this->convertedPatient->id,
                'full_name' => $this->convertedPatient->full_name,
                'mrn' => $this->convertedPatient->mrn,
            ] : null),
            'converted_at' => $this->iso($this->converted_at),
            'allowed_transitions' => array_map(fn ($s) => $s->value, $this->status->allowedTransitions()),
            'activities' => $this->whenLoaded('activities', fn () => $this->activities->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'body' => $a->body,
                'meta' => $a->meta,
                'user' => $a->user?->name,
                'created_at' => $this->iso($a->created_at),
            ])),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
