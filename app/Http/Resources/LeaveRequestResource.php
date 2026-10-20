<?php

namespace App\Http\Resources;

use App\Models\LeaveRequest;
use Illuminate\Http\Request;

/** @mixin LeaveRequest */
class LeaveRequestResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name, 'color' => $this->user->color, 'is_practitioner' => $this->user->is_practitioner]),
            'leave_type' => $this->whenLoaded('type', fn () => ['id' => $this->type->id, 'name' => $this->type->name, 'code' => $this->type->code, 'color' => $this->type->color, 'is_paid' => $this->type->is_paid]),
            'start_date' => $this->date($this->start_date),
            'end_date' => $this->date($this->end_date),
            'half_day' => $this->half_day,
            'days' => $this->days,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider?->name),
            'decided_at' => $this->iso($this->decided_at),
            'decision_note' => $this->decision_note,
            /** Blocking appointments the practitioner has in the range (pending requests only). */
            'conflicting_appointments' => $this->when(isset($this->conflicting_appointments), fn () => (int) $this->conflicting_appointments),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
