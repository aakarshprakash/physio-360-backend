<?php

namespace App\Services\Scheduling;

use App\Enums\AppointmentType;
use Carbon\CarbonImmutable;

/**
 * A request to occupy a practitioner (and optionally a room) for a patient.
 * $localStart is wall-clock time in $timezone (the branch timezone).
 */
final class BookingRequest
{
    public function __construct(
        public readonly int $patientId,
        public readonly int $practitionerId,
        public readonly int $branchId,
        public readonly CarbonImmutable $localStart,
        public readonly int $durationMinutes,
        public readonly string $timezone,
        public readonly ?int $roomId = null,
        public readonly ?int $serviceId = null,
        public readonly AppointmentType $type = AppointmentType::Clinic,
        public readonly int $travelBufferMinutes = 0,
        public readonly ?string $homeVisitAddress = null,
        public readonly ?string $notes = null,
        public readonly string $bookedVia = 'staff',
        public readonly bool $allowOutsideHours = false,
        public readonly ?int $ignoreAppointmentId = null,
    ) {}

    public function startsAtUtc(): CarbonImmutable
    {
        return $this->localStart->utc();
    }

    public function endsAtUtc(): CarbonImmutable
    {
        return $this->startsAtUtc()->addMinutes($this->durationMinutes);
    }

    public function blockedFromUtc(): CarbonImmutable
    {
        return $this->startsAtUtc()->subMinutes($this->travelBufferMinutes);
    }

    public function blockedUntilUtc(): CarbonImmutable
    {
        return $this->endsAtUtc()->addMinutes($this->travelBufferMinutes);
    }

    public function withLocalStart(CarbonImmutable $localStart): self
    {
        return new self(
            patientId: $this->patientId,
            practitionerId: $this->practitionerId,
            branchId: $this->branchId,
            localStart: $localStart,
            durationMinutes: $this->durationMinutes,
            timezone: $this->timezone,
            roomId: $this->roomId,
            serviceId: $this->serviceId,
            type: $this->type,
            travelBufferMinutes: $this->travelBufferMinutes,
            homeVisitAddress: $this->homeVisitAddress,
            notes: $this->notes,
            bookedVia: $this->bookedVia,
            allowOutsideHours: $this->allowOutsideHours,
            ignoreAppointmentId: $this->ignoreAppointmentId,
        );
    }
}
