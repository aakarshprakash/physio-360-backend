<?php

namespace App\Services\Scheduling;

use App\Enums\AppointmentStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\SendAppointmentConfirmation;
use App\Models\Appointment;
use App\Models\AppointmentSeries;
use App\Models\Patient;
use App\Models\Room;
use App\Models\User;
use App\Services\Billing\PackageService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Overlap prevention on MySQL 8 (no exclusion constraints):
 *
 *  1. Start a READ COMMITTED transaction (so reads after the lock see rows
 *     committed by the transaction we waited on).
 *  2. SELECT ... FOR UPDATE the practitioner, then the room, then the patient
 *     row, always in that order, which serialises competing bookings for the
 *     same resources without deadlock cycles.
 *  3. Run the overlap queries (ConflictDetector) and insert.
 *
 * Any concurrent request touching the same practitioner/room/patient blocks at
 * step 2 until we commit, then sees our row at step 3 and gets a 409.
 */
class BookingService
{
    public function __construct(
        private readonly ConflictDetector $conflicts,
        private readonly WorkingHours $workingHours,
        private readonly PackageService $packages,
    ) {}

    public function book(BookingRequest $request, ?int $createdBy = null): Appointment
    {
        $appointment = $this->withResourceLocks($request, function () use ($request, $createdBy) {
            $this->assertBookable($request);

            return Appointment::query()->create($this->attributes($request) + [
                'status' => AppointmentStatus::Scheduled,
                'booked_via' => $request->bookedVia,
                'created_by' => $createdBy,
            ]);
        });

        SendAppointmentConfirmation::dispatch($appointment->id)->afterCommit();

        return $appointment;
    }

    public function reschedule(Appointment $appointment, BookingRequest $request): Appointment
    {
        if (! $appointment->status->isReschedulable()) {
            throw new DomainRuleViolation("A {$appointment->status->value} appointment cannot be rescheduled.", 'not_reschedulable');
        }

        return $this->withResourceLocks($request, function () use ($appointment, $request) {
            $this->assertBookable($request);
            $appointment->fill($this->attributes($request) + ['reminder_sent_at' => null])->save();

            return $appointment;
        });
    }

    public function transition(Appointment $appointment, AppointmentStatus $to, ?string $reason = null, ?int $actorId = null): Appointment
    {
        return $this->transaction(function () use ($appointment, $to, $reason, $actorId) {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($to)) {
                throw new DomainRuleViolation(
                    "Cannot change an appointment from {$locked->status->value} to {$to->value}.",
                    'invalid_transition',
                    ['from' => $locked->status->value, 'to' => $to->value],
                );
            }

            $now = now();
            $locked->status = $to;
            match ($to) {
                AppointmentStatus::CheckedIn => $locked->checked_in_at = $now,
                AppointmentStatus::InProgress => $locked->started_at = $now,
                AppointmentStatus::Completed => $locked->completed_at = $now,
                AppointmentStatus::Cancelled => [$locked->cancelled_at, $locked->cancellation_reason] = [$now, $reason ?: 'Cancelled'],
                default => null,
            };
            $locked->save();

            if ($to === AppointmentStatus::Completed) {
                $this->packages->consumeForAppointment($locked, $actorId);
            }

            return $locked;
        });
    }

    /**
     * Book every occurrence of a weekly series. $onConflict:
     *   "fail" - book nothing if any occurrence conflicts (409 lists them all)
     *   "skip" - book the free occurrences and report the skipped ones
     *
     * @return array{series: AppointmentSeries, created: list<Appointment>, skipped: list<array>}
     */
    public function bookSeries(BookingRequest $template, SeriesRule $rule, string $onConflict = 'fail', ?int $createdBy = null): array
    {
        $occurrences = $rule->occurrences($template->localStart, $template->timezone);

        if ($occurrences === []) {
            throw new DomainRuleViolation('The recurrence rule produces no occurrences.', 'empty_series');
        }

        $result = $this->withResourceLocks($template, function () use ($template, $rule, $occurrences, $onConflict, $createdBy) {
            $series = AppointmentSeries::query()->create([
                'patient_id' => $template->patientId,
                'practitioner_id' => $template->practitionerId,
                'branch_id' => $template->branchId,
                'service_id' => $template->serviceId,
                'room_id' => $template->roomId,
                'local_start_time' => $template->localStart->format('H:i:s'),
                'duration_minutes' => $template->durationMinutes,
                'timezone' => $template->timezone,
                'frequency' => 'weekly',
                'interval' => $rule->interval,
                'by_weekdays' => $rule->weekdays,
                'starts_on' => $template->localStart->toDateString(),
                'until_on' => $rule->until?->toDateString(),
                'occurrences' => $rule->count,
                'created_by' => $createdBy,
            ]);

            $created = [];
            $skipped = [];

            foreach ($occurrences as $localStart) {
                $request = $template->withLocalStart($localStart);
                $problem = $this->problemWith($request);

                if ($problem !== null) {
                    $skipped[] = ['local_start' => $localStart->format('Y-m-d H:i')] + $problem;

                    continue;
                }

                $created[] = Appointment::query()->create($this->attributes($request) + [
                    'status' => AppointmentStatus::Scheduled,
                    'booked_via' => $template->bookedVia,
                    'series_id' => $series->id,
                    'created_by' => $createdBy,
                ]);
            }

            if ($onConflict === 'fail' && $skipped !== []) {
                throw new SlotConflict($skipped, count($skipped).' occurrence(s) of the series conflict with existing appointments.');
            }

            if ($created === []) {
                throw new SlotConflict($skipped, 'No occurrence of the series could be booked.');
            }

            return ['series' => $series, 'created' => $created, 'skipped' => $skipped];
        });

        foreach ($result['created'] as $appointment) {
            SendAppointmentConfirmation::dispatch($appointment->id)->afterCommit();
        }

        return $result;
    }

    /**
     * Re-derive UTC instants from stored wall-clock time after a tzdata update.
     * Returns the number of appointments whose instant moved.
     */
    public function rebaseFromLocalTime(Appointment $appointment): bool
    {
        $local = CarbonImmutable::parse($appointment->local_starts_at->format('Y-m-d H:i:s'), $appointment->timezone);
        $startsAt = $local->utc();

        if ($startsAt->equalTo($appointment->starts_at)) {
            return false;
        }

        $duration = $appointment->durationMinutes();
        $buffer = $appointment->travel_buffer_minutes;
        $appointment->forceFill([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($duration),
            'blocked_from' => $startsAt->subMinutes($buffer),
            'blocked_until' => $startsAt->addMinutes($duration + $buffer),
        ])->save();

        return true;
    }

    private function withResourceLocks(BookingRequest $request, Closure $callback): mixed
    {
        return $this->transaction(function () use ($request, $callback) {
            $practitioner = User::query()->whereKey($request->practitionerId)->lockForUpdate()->first();

            if (! $practitioner || ! $practitioner->is_practitioner || ! $practitioner->is_active) {
                throw new DomainRuleViolation('The selected practitioner is not available for booking.', 'invalid_practitioner');
            }

            if (! $practitioner->branches()->whereKey($request->branchId)->exists()) {
                throw new DomainRuleViolation('The practitioner does not work at this branch.', 'practitioner_not_in_branch');
            }

            if ($request->roomId !== null) {
                $room = Room::query()->whereKey($request->roomId)->lockForUpdate()->first();
                if (! $room || ! $room->is_active || $room->branch_id !== $request->branchId) {
                    throw new DomainRuleViolation('The selected room is not available at this branch.', 'invalid_room');
                }
            }

            if (! Patient::query()->whereKey($request->patientId)->lockForUpdate()->exists()) {
                throw new DomainRuleViolation('Patient not found.', 'invalid_patient');
            }

            return $callback();
        });
    }

    private function assertBookable(BookingRequest $request): void
    {
        $problem = $this->problemWith($request);

        if ($problem === null) {
            return;
        }

        if ($problem['reason'] === 'practitioner_on_leave') {
            throw new DomainRuleViolation('The practitioner is on approved leave at that time.', 'practitioner_on_leave');
        }

        if ($problem['reason'] === 'outside_working_hours') {
            throw new DomainRuleViolation('The time is outside the practitioner\'s working hours at this branch.', 'outside_working_hours');
        }

        throw new SlotConflict($problem['conflicts']);
    }

    /** @return array{reason: string, conflicts: list<array>}|null */
    private function problemWith(BookingRequest $request): ?array
    {
        // Approved leave is not overridable like working hours: cancel the leave instead.
        if ($this->workingHours->onLeaveDuring($request)) {
            return ['reason' => 'practitioner_on_leave', 'conflicts' => []];
        }

        if (! $request->allowOutsideHours && ! $this->workingHours->covers($request)) {
            return ['reason' => 'outside_working_hours', 'conflicts' => []];
        }

        $conflicts = $this->conflicts->find($request);

        return $conflicts === [] ? null : ['reason' => 'slot_conflict', 'conflicts' => $conflicts];
    }

    private function attributes(BookingRequest $request): array
    {
        return [
            'patient_id' => $request->patientId,
            'practitioner_id' => $request->practitionerId,
            'branch_id' => $request->branchId,
            'room_id' => $request->roomId,
            'service_id' => $request->serviceId,
            'type' => $request->type,
            'starts_at' => $request->startsAtUtc(),
            'ends_at' => $request->endsAtUtc(),
            'blocked_from' => $request->blockedFromUtc(),
            'blocked_until' => $request->blockedUntilUtc(),
            'local_starts_at' => $request->localStart->format('Y-m-d H:i:s'),
            'timezone' => $request->timezone,
            'travel_buffer_minutes' => $request->travelBufferMinutes,
            'home_visit_address' => $request->homeVisitAddress,
            'notes' => $request->notes,
        ];
    }

    /** READ COMMITTED + deadlock retry. Isolation can only be set outside an open transaction. */
    private function transaction(Closure $callback): mixed
    {
        if (DB::transactionLevel() === 0 && DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return DB::transaction($callback, attempts: 3);
    }
}
