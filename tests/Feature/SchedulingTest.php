<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Room;
use App\Services\Scheduling\BookingService;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->branch] = clinic();
    $this->therapist = staff($this->tenant, Role::Therapist);
    $this->patient = patient($this->tenant);
    $this->room = inTenant($this->tenant, fn () => Room::query()->firstOrFail());
    $this->service = service($this->tenant, 'FOLLOW'); // 45 minutes
    $this->at = nextWorkday('10:00');
});

function book(array $overrides = []): TestResponse
{
    $t = test();

    return actingAsStaff($t->owner)->postJson('/api/appointments', array_merge([
        'patient_id' => $t->patient->id,
        'practitioner_id' => $t->therapist->id,
        'branch_id' => $t->branch->id,
        'service_id' => $t->service->id,
        'room_id' => $t->room->id,
        'local_start' => $t->at->format('Y-m-d\TH:i'),
    ], $overrides));
}

it('books an appointment storing both local time and UTC instant', function () {
    book()->assertCreated()
        ->assertJsonPath('data.local_start', $this->at->format('Y-m-d\TH:i'))
        ->assertJsonPath('data.starts_at', $this->at->utc()->toIso8601ZuluString()) // 10:00 IST = 04:30Z
        ->assertJsonPath('data.duration_minutes', 45)
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.timezone', 'Asia/Kolkata');

    expect($this->at->utc()->format('H:i'))->toBe('04:30');
});

it('prevents practitioner double booking', function () {
    book()->assertCreated();
    $other = patient($this->tenant);

    book(['patient_id' => $other->id, 'room_id' => null, 'local_start' => $this->at->addMinutes(30)->format('Y-m-d\TH:i')])
        ->assertStatus(409)
        ->assertJsonPath('code', 'slot_conflict')
        ->assertJsonPath('conflicts.0.resource', 'practitioner');
});

it('prevents room double booking across practitioners', function () {
    book()->assertCreated();
    $other = staff($this->tenant, Role::Therapist);

    book(['practitioner_id' => $other->id, 'patient_id' => patient($this->tenant)->id])
        ->assertStatus(409)->assertJsonPath('conflicts.0.resource', 'room');
});

it('prevents a patient being booked twice at once', function () {
    book()->assertCreated();
    $other = staff($this->tenant, Role::Therapist);

    book(['practitioner_id' => $other->id, 'room_id' => null])
        ->assertStatus(409)->assertJsonPath('conflicts.0.resource', 'patient');
});

it('allows back-to-back appointments', function () {
    book()->assertCreated();
    book(['patient_id' => patient($this->tenant)->id, 'local_start' => $this->at->addMinutes(45)->format('Y-m-d\TH:i')])->assertCreated();
});

it('frees the slot when an appointment is cancelled', function () {
    $id = book()->assertCreated()->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/appointments/{$id}/status", ['status' => 'cancelled', 'reason' => 'Unwell'])->assertOk();

    book(['patient_id' => patient($this->tenant)->id])->assertCreated();
});

it('blocks travel time around home visits', function () {
    book(['type' => 'home_visit', 'room_id' => null, 'home_visit_address' => '12 Marine Drive', 'travel_buffer_minutes' => 30])->assertCreated();

    // Clinic visit starting 15 minutes after the home visit ends: inside the 30-min travel buffer.
    book(['patient_id' => patient($this->tenant)->id, 'local_start' => $this->at->addMinutes(60)->format('Y-m-d\TH:i')])
        ->assertStatus(409)->assertJsonPath('conflicts.0.resource', 'practitioner');

    book(['patient_id' => patient($this->tenant)->id, 'local_start' => $this->at->addMinutes(75)->format('Y-m-d\TH:i')])->assertCreated();
});

it('requires a home visit address', function () {
    book(['type' => 'home_visit', 'room_id' => null])->assertUnprocessable()->assertJsonValidationErrors('home_visit_address');
});

it('enforces working hours unless explicitly overridden', function () {
    $late = nextWorkday('21:00');

    book(['local_start' => $late->format('Y-m-d\TH:i')])->assertUnprocessable()->assertJsonPath('code', 'outside_working_hours');
    book(['local_start' => $late->format('Y-m-d\TH:i'), 'allow_outside_hours' => true])->assertCreated();
});

it('refuses practitioners who do not work at the branch', function () {
    $other = inTenant($this->tenant, fn () => Branch::query()->create(['name' => 'Other', 'code' => 'OTH']));
    $outsider = staff($this->tenant, Role::Therapist, [], [$other->id]);

    book(['practitioner_id' => $outsider->id])->assertUnprocessable()->assertJsonPath('code', 'practitioner_not_in_branch');
});

it('reschedules without conflicting with itself', function () {
    $id = book()->assertCreated()->json('data.id');

    actingAsStaff($this->owner)->putJson("/api/appointments/{$id}", ['local_start' => $this->at->addMinutes(15)->format('Y-m-d\TH:i')])
        ->assertOk()->assertJsonPath('data.local_start', $this->at->addMinutes(15)->format('Y-m-d\TH:i'));
});

it('walks the visit workflow and rejects invalid transitions', function () {
    $id = book()->assertCreated()->json('data.id');
    $go = fn (string $s) => actingAsStaff($this->owner)->postJson("/api/appointments/{$id}/status", ['status' => $s]);

    $go('completed')->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');
    $go('confirmed')->assertOk()->assertJsonPath('data.allowed_transitions', ['checked_in', 'cancelled', 'no_show']);
    $go('checked_in')->assertOk();
    $go('in_progress')->assertOk();
    $go('completed')->assertOk()->assertJsonPath('data.status', 'completed');
    $go('cancelled')->assertUnprocessable();

    actingAsStaff($this->owner)->putJson("/api/appointments/{$id}", ['local_start' => $this->at->addHour()->format('Y-m-d\TH:i')])
        ->assertUnprocessable()->assertJsonPath('code', 'not_reschedulable');
});

it('requires a reason to cancel', function () {
    $id = book()->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/appointments/{$id}/status", ['status' => 'cancelled'])->assertJsonValidationErrors('reason');
});

it('books a weekly series and refuses it atomically when any occurrence conflicts', function () {
    $monday = nextWorkday('09:00');
    while ($monday->dayOfWeek !== 1) {
        $monday = $monday->addDay();
    }

    // Occupy the third occurrence (next week's Monday).
    book(['local_start' => $monday->addWeek()->format('Y-m-d\TH:i'), 'patient_id' => patient($this->tenant)->id, 'room_id' => null]);

    $series = ['local_start' => $monday->format('Y-m-d\TH:i'), 'room_id' => null, 'recurrence' => ['weekdays' => [1, 4], 'count' => 4]];

    book($series)->assertStatus(409)->assertJsonCount(1, 'conflicts');
    expect(inTenant($this->tenant, fn () => Appointment::query()->count()))->toBe(1);

    book($series + ['on_conflict' => 'skip'])->assertCreated()
        ->assertJsonCount(3, 'data')
        ->assertJsonCount(1, 'skipped');
});

it('lists appointments for a date range in clinic time', function () {
    book()->assertCreated();
    $day = $this->at->toDateString();

    actingAsStaff($this->owner)->getJson("/api/appointments?from={$day}&to={$day}")
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.patient.id', $this->patient->id)
        ->assertJsonPath('data.0.practitioner.id', $this->therapist->id);

    $next = $this->at->addDay()->toDateString();
    actingAsStaff($this->owner)->getJson("/api/appointments?from={$next}&to={$next}")->assertOk()->assertJsonCount(0, 'data');
});

it('offers availability that excludes busy slots and honours working hours', function () {
    book()->assertCreated(); // 10:00-10:45

    $slots = collect(actingAsStaff($this->owner)->getJson('/api/availability?'.http_build_query([
        'practitioner_id' => $this->therapist->id, 'branch_id' => $this->branch->id,
        'date' => $this->at->toDateString(), 'duration_minutes' => 45,
    ]))->assertOk()->json('data'))->pluck('local_start')->map(fn ($s) => substr($s, 11));

    expect($slots)->toContain('09:00', '09:15', '10:45', '14:00', '18:15')
        ->not->toContain('09:30', '10:00', '10:30', '12:30', '13:00', '18:30', '08:45');
});

it('re-derives UTC from stored local time after timezone-rule changes', function () {
    $id = book()->json('data.id');

    inTenant($this->tenant, function () use ($id) {
        $appointment = Appointment::query()->findOrFail($id);
        // Simulate a stale instant (as if computed under old tz rules).
        $appointment->forceFill(['starts_at' => $appointment->starts_at->subHour(), 'ends_at' => $appointment->ends_at->subHour()])->saveQuietly();

        expect(app(BookingService::class)->rebaseFromLocalTime($appointment->fresh()))->toBeTrue();
        expect($appointment->fresh()->starts_at->toIso8601ZuluString())->toBe($this->at->utc()->toIso8601ZuluString());
    });
});

it('creates a draft invoice from a completed visit exactly once', function () {
    $id = book()->json('data.id');

    actingAsStaff($this->owner)->postJson("/api/appointments/{$id}/invoice")->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.total_minor', $this->service->price_minor)
        ->assertJsonPath('data.items.0.appointment_id', $id);

    actingAsStaff($this->owner)->postJson("/api/appointments/{$id}/invoice")->assertUnprocessable()->assertJsonPath('code', 'already_invoiced');
});

it('exposes calendar resources per branch', function () {
    actingAsStaff($this->owner)->getJson('/api/calendar/resources?branch_id='.$this->branch->id)
        ->assertOk()
        ->assertJsonCount(3, 'data.rooms')
        ->assertJson(fn ($json) => $json->has('data.practitioners', 2)->etc()); // owner + therapist
});

it('stays consistent when two staff book the same slot (sequential race)', function () {
    $other = patient($this->tenant);
    $first = book();
    $second = book(['patient_id' => $other->id, 'room_id' => null]);

    expect([$first->status(), $second->status()])->toBe([201, 409])
        ->and(inTenant($this->tenant, fn () => Appointment::query()->whereIn('status', AppointmentStatus::blockingValues())->count()))->toBe(1);
});
