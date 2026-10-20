<?php

namespace App\Services\Scheduling;

use App\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * Overlap rules (half-open intervals, so back-to-back slots never conflict):
 *  - practitioner: busy windows including home-visit travel buffers
 *  - room:         appointment time
 *  - patient:      appointment time (a patient cannot be in two places)
 *
 * Callers MUST hold the practitioner/room/patient row locks (see BookingService)
 * so that the check-then-insert is atomic across concurrent requests.
 * One query per resource so each can use its covering index.
 */
class ConflictDetector
{
    /** @return list<array{resource: string, appointment_id: int, starts_at: string, ends_at: string, local_start: string}> */
    public function find(BookingRequest $request): array
    {
        $start = $request->startsAtUtc();
        $end = $request->endsAtUtc();

        $conflicts = [];

        $this->collect($conflicts, 'practitioner', Appointment::query()
            ->where('practitioner_id', $request->practitionerId)
            ->where('blocked_from', '<', $request->blockedUntilUtc())
            ->where('blocked_until', '>', $request->blockedFromUtc()), $request);

        if ($request->roomId !== null) {
            $this->collect($conflicts, 'room', Appointment::query()
                ->where('room_id', $request->roomId)
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start), $request);
        }

        $this->collect($conflicts, 'patient', Appointment::query()
            ->where('patient_id', $request->patientId)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start), $request);

        return $conflicts;
    }

    private function collect(array &$conflicts, string $resource, $query, BookingRequest $request): void
    {
        $rows = $query->blocking()
            ->when($request->ignoreAppointmentId, fn ($q, $id) => $q->whereKeyNot($id))
            ->get(['id', 'starts_at', 'ends_at', 'local_starts_at']);

        foreach ($rows as $row) {
            $conflicts[] = [
                'resource' => $resource,
                'appointment_id' => $row->id,
                'starts_at' => $row->starts_at->toIso8601ZuluString(),
                'ends_at' => $row->ends_at->toIso8601ZuluString(),
                'local_start' => CarbonImmutable::parse($row->local_starts_at)->format('Y-m-d H:i'),
            ];
        }
    }
}
