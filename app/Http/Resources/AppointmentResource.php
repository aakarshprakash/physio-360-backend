<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** @mixin Appointment */
class AppointmentResource extends Resource
{
    public function toArray(Request $request): array
    {
        $localStart = CarbonImmutable::parse($this->local_starts_at->format('Y-m-d H:i:s'));

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'type' => $this->type->value,
            'starts_at' => $this->iso($this->starts_at),
            'ends_at' => $this->iso($this->ends_at),
            'local_start' => $localStart->format('Y-m-d\TH:i'),
            'local_end' => $localStart->addMinutes($this->durationMinutes())->format('Y-m-d\TH:i'),
            'timezone' => $this->timezone,
            'duration_minutes' => $this->durationMinutes(),
            'branch_id' => $this->branch_id,
            'series_id' => $this->series_id,
            'patient' => $this->whenLoaded('patient', fn () => [
                'id' => $this->patient->id,
                'full_name' => $this->patient->full_name,
                'mrn' => $this->patient->mrn,
            ]),
            'practitioner' => $this->whenLoaded('practitioner', fn () => [
                'id' => $this->practitioner->id,
                'name' => $this->practitioner->name,
                'color' => $this->practitioner->color,
            ]),
            'room' => $this->whenLoaded('room', fn () => $this->room ? ['id' => $this->room->id, 'name' => $this->room->name] : null),
            'service' => $this->whenLoaded('service', fn () => $this->service ? [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'color' => $this->service->color,
            ] : null),
            'travel_buffer_minutes' => $this->travel_buffer_minutes,
            'home_visit_address' => $this->home_visit_address,
            'notes' => $this->notes,
            'booked_via' => $this->booked_via,
            'cancellation_reason' => $this->cancellation_reason,
            'checked_in_at' => $this->iso($this->checked_in_at),
            'completed_at' => $this->iso($this->completed_at),
            'cancelled_at' => $this->iso($this->cancelled_at),
            'allowed_transitions' => array_map(fn ($s) => $s->value, $this->status->allowedTransitions()),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
