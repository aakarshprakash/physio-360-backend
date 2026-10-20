<?php

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Exercise;
use App\Services\Billing\InvoiceService;
use App\Services\Scheduling\BookingService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->branch] = clinic();
    $this->therapist = staff($this->tenant, Role::Therapist);
    $this->patient = patient($this->tenant, ['email' => 'anil@example.com']);
    inTenant($this->tenant, fn () => $this->patient->forceFill(['portal_enabled' => true, 'portal_password' => 'Patient123'])->save());
    $this->service = service($this->tenant, 'FOLLOW');
});

it('signs patients in with their clinic code', function () {
    $this->getJson("/api/patient/clinics/{$this->tenant->slug}")->assertOk()->assertJsonPath('data.name', $this->tenant->name);

    $this->postJson('/api/patient/auth/login', ['clinic' => $this->tenant->slug, 'email' => 'ANIL@example.com', 'password' => 'Patient123'])
        ->assertOk()->assertJsonPath('data.patient.id', $this->patient->id)->assertJsonPath('data.clinic.currency.code', 'INR');

    $this->getJson('/api/patient/me')->assertOk();
});

it('does not reveal whether a patient exists', function () {
    ['tenant' => $other] = clinic();

    foreach ([
        ['clinic' => $this->tenant->slug, 'email' => 'anil@example.com', 'password' => 'wrong'],
        ['clinic' => $this->tenant->slug, 'email' => 'ghost@example.com', 'password' => 'Patient123'],
        ['clinic' => $other->slug, 'email' => 'anil@example.com', 'password' => 'Patient123'], // right person, wrong clinic
        ['clinic' => 'no-such-clinic', 'email' => 'anil@example.com', 'password' => 'Patient123'],
    ] as $attempt) {
        freshBrowser()->postJson('/api/patient/auth/login', $attempt)->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'These details do not match an active patient account.');
    }
});

it('blocks patients whose portal access was disabled', function () {
    actingAsPatient($this->patient);
    $this->patient->forceFill(['portal_enabled' => false])->saveQuietly();

    $this->getJson('/api/patient/me')->assertForbidden()->assertJsonPath('code', 'portal_disabled');
});

it('books only offered slots and hides other patients\' bookings', function () {
    $day = nextWorkday('10:00', daysAhead: 2);
    $params = ['practitioner_id' => $this->therapist->id, 'branch_id' => $this->branch->id, 'service_id' => $this->service->id];

    $slots = actingAsPatient($this->patient)->getJson('/api/patient/availability?'.http_build_query($params + ['date' => $day->toDateString()]))
        ->assertOk()->json('data');
    expect(collect($slots)->pluck('local_start'))->toContain($day->format('Y-m-d\TH:i'));

    actingAsPatient($this->patient)->postJson('/api/patient/appointments', $params + ['local_start' => $day->format('Y-m-d\TH:i')])
        ->assertCreated()->assertJsonPath('data.status', 'scheduled');

    // Off-grid / outside hours times are refused.
    actingAsPatient($this->patient)->postJson('/api/patient/appointments', $params + ['local_start' => $day->setTime(22, 0)->format('Y-m-d\TH:i')])
        ->assertUnprocessable()->assertJsonPath('code', 'slot_unavailable');

    // Another patient trying the same slot gets a generic message, no conflict details.
    $other = patient($this->tenant, ['email' => 'other@example.com']);
    actingAsPatient($other)->postJson('/api/patient/appointments', $params + ['local_start' => $day->format('Y-m-d\TH:i')])
        ->assertUnprocessable()->assertJsonMissingPath('conflicts');

    expect(inTenant($this->tenant, fn () => Appointment::query()->where('booked_via', 'portal')->count()))->toBe(1);
});

it('respects the minimum booking lead time', function () {
    $this->tenant->update(['settings' => array_merge($this->tenant->settings, ['portal_min_lead_minutes' => 60 * 24 * 7])]);
    $day = nextWorkday('10:00', daysAhead: 2);

    actingAsPatient($this->patient)->getJson('/api/patient/availability?'.http_build_query([
        'practitioner_id' => $this->therapist->id, 'branch_id' => $this->branch->id, 'service_id' => $this->service->id, 'date' => $day->toDateString(),
    ]))->assertOk()->assertJsonCount(0, 'data');
});

it('lets patients cancel only outside the cancellation window', function () {
    $soon = inTenant($this->tenant, fn () => app(BookingService::class)->book(bookingRequest($this->patient, $this->therapist, now('Asia/Kolkata')->addHours(3)->startOfHour()->toImmutable(), ['allowOutsideHours' => true])));
    $later = inTenant($this->tenant, fn () => app(BookingService::class)->book(bookingRequest($this->patient, $this->therapist, nextWorkday('11:00', daysAhead: 3))));

    actingAsPatient($this->patient)->postJson("/api/patient/appointments/{$soon->id}/cancel")->assertUnprocessable()->assertJsonPath('code', 'too_late_to_cancel');
    actingAsPatient($this->patient)->postJson("/api/patient/appointments/{$later->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('only ever exposes the signed-in patient\'s own records', function () {
    $other = patient($this->tenant);
    $theirs = inTenant($this->tenant, fn () => app(BookingService::class)->book(bookingRequest($other, $this->therapist, nextWorkday('12:00', daysAhead: 3))));

    actingAsPatient($this->patient)->getJson('/api/patient/appointments')->assertOk()->assertJsonCount(0, 'data.upcoming');
    actingAsPatient($this->patient)->postJson("/api/patient/appointments/{$theirs->id}/cancel")->assertNotFound();

    $invoice = inTenant($this->tenant, function () use ($other) {
        $i = app(InvoiceService::class)->createDraft(['patient_id' => $other->id, 'branch_id' => $this->branch->id, 'items' => [['service_id' => $this->service->id]]], $this->owner->id);

        return app(InvoiceService::class)->post($i, $this->owner->id);
    });
    actingAsPatient($this->patient)->get("/api/patient/invoices/{$invoice->id}/pdf")->assertNotFound();
    actingAsPatient($this->patient)->getJson('/api/patient/invoices')->assertJsonCount(0, 'data');
});

it('tracks home exercise adherence once per day', function () {
    $exercise = inTenant($this->tenant, fn () => Exercise::query()->firstOrFail());
    $planId = actingAsStaff($this->therapist)->postJson("/api/patients/{$this->patient->id}/treatment-plans", [
        'title' => 'Knee plan', 'start_date' => now()->toDateString(), 'exercises' => [['exercise_id' => $exercise->id]],
    ])->json('data.id');

    $rowId = actingAsPatient($this->patient)->getJson('/api/patient/treatment-plans')->assertOk()
        ->assertJsonPath('data.0.exercises.0.done_today', false)->json('data.0.exercises.0.id');

    actingAsPatient($this->patient)->postJson('/api/patient/exercise-logs', ['treatment_plan_exercise_id' => $rowId, 'pain_score' => 3])->assertCreated();
    actingAsPatient($this->patient)->postJson('/api/patient/exercise-logs', ['treatment_plan_exercise_id' => $rowId, 'pain_score' => 2])->assertCreated();

    actingAsPatient($this->patient)->getJson('/api/patient/treatment-plans')
        ->assertJsonPath('data.0.exercises.0.done_today', true)
        ->assertJsonPath('data.0.exercises.0.completed_days', 1);

    actingAsStaff($this->therapist)->getJson("/api/treatment-plans/{$planId}/adherence")->assertOk()->assertJsonCount(1, 'data.logs');

    actingAsPatient($this->patient)->postJson('/api/patient/exercise-logs', ['treatment_plan_exercise_id' => $rowId, 'done' => false])
        ->assertOk()->assertJsonPath('data.done_today', false);
});

it('signs consent forms from the app', function () {
    Storage::fake('local');
    $pending = actingAsPatient($this->patient)->getJson('/api/patient/consents')->assertOk()->json('data.pending');

    actingAsPatient($this->patient)->postJson("/api/patient/consents/{$pending[0]['id']}/sign", [
        'signature' => signaturePng(), 'signer_name' => $this->patient->full_name,
    ])->assertUnprocessable()->assertJsonValidationErrors('agree');

    actingAsPatient($this->patient)->postJson("/api/patient/consents/{$pending[0]['id']}/sign", [
        'signature' => signaturePng(), 'signer_name' => $this->patient->full_name, 'agree' => true,
    ])->assertCreated()->assertJsonPath('data.signed_via', 'portal');

    actingAsPatient($this->patient)->getJson('/api/patient/consents')->assertJsonCount(count($pending) - 1, 'data.pending');
});

it('collects one feedback rating per completed visit', function () {
    $appointment = inTenant($this->tenant, fn () => app(BookingService::class)->book(bookingRequest($this->patient, $this->therapist, nextWorkday('15:00'))));

    actingAsPatient($this->patient)->postJson('/api/patient/feedback', ['appointment_id' => $appointment->id, 'rating' => 5])->assertNotFound();

    foreach (['confirmed', 'checked_in', 'completed'] as $s) {
        actingAsStaff($this->owner)->postJson("/api/appointments/{$appointment->id}/status", ['status' => $s]);
    }

    actingAsPatient($this->patient)->postJson('/api/patient/feedback', ['appointment_id' => $appointment->id, 'rating' => 5, 'comment' => 'Great'])->assertCreated();
    actingAsPatient($this->patient)->postJson('/api/patient/feedback', ['appointment_id' => $appointment->id, 'rating' => 1])->assertUnprocessable();

    actingAsStaff($this->owner)->getJson('/api/feedback')->assertOk()->assertJsonPath('data.0.rating', 5);
});

it('lets patients self-report pain onto the clinical chart', function () {
    actingAsPatient($this->patient)->postJson('/api/patient/progress', ['pain_score' => 4])->assertCreated();

    actingAsStaff($this->therapist)->getJson("/api/patients/{$this->patient->id}/progress")->assertJsonPath('data.0.measure', 'NPRS (self-reported)');
});
