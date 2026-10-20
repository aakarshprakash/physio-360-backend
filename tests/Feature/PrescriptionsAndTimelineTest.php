<?php

use App\Enums\Role;
use App\Models\PackagePlan;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->branch] = clinic();
    $this->therapist = staff($this->tenant, Role::Therapist);
    $this->desk = staff($this->tenant, Role::Receptionist);
    $this->patient = patient($this->tenant);
});

function assessmentFor(object $t): int
{
    $id = actingAsStaff($t->therapist)->postJson("/api/patients/{$t->patient->id}/assessments", [
        'chief_complaint' => 'Low back pain radiating to the left leg',
        'pain_score' => 6,
        'diagnosis' => 'Lumbar radiculopathy L5 (left)',
    ])->assertCreated()->json('data.id');
    actingAsStaff($t->therapist)->postJson("/api/assessments/{$id}/sign")->assertOk();

    return $id;
}

it('issues numbered prescriptions prefilled from the assessment and plan', function () {
    $assessmentId = assessmentFor($this);
    $exercises = actingAsStaff($this->therapist)->getJson('/api/exercises?body_region=back')->json('data');
    $planId = actingAsStaff($this->therapist)->postJson("/api/patients/{$this->patient->id}/treatment-plans", [
        'title' => 'Lumbar stabilisation', 'start_date' => now()->toDateString(),
        'exercises' => [['exercise_id' => $exercises[0]['id'], 'sets' => 3, 'reps' => 10]],
    ])->json('data.id');

    $res = actingAsStaff($this->therapist)->postJson("/api/patients/{$this->patient->id}/prescriptions", [
        'assessment_id' => $assessmentId,
        'treatment_plan_id' => $planId,
        'modalities' => [['name' => 'TENS', 'parameters' => '100 Hz, conventional', 'duration_minutes' => 15, 'sessions' => 8]],
        'advice' => 'Avoid prolonged sitting.',
        'review_on' => now()->addWeek()->toDateString(),
    ])->assertCreated()
        ->assertJsonPath('data.number', 'RX-KOC-000001')
        ->assertJsonPath('data.diagnosis', 'Lumbar radiculopathy L5 (left)')
        ->assertJsonPath('data.exercises.0.name', $exercises[0]['name'])
        ->assertJsonPath('data.modalities.0.name', 'TENS')
        ->assertJsonPath('data.can_edit', true);

    actingAsStaff($this->therapist)->postJson("/api/patients/{$this->patient->id}/prescriptions", ['advice' => 'Second'])
        ->assertCreated()->assertJsonPath('data.number', 'RX-KOC-000002');

    // Clinical content is encrypted at rest.
    $raw = inTenant($this->tenant, fn () => DB::table('prescriptions')->where('id', $res->json('data.id'))->value('diagnosis'));
    expect($raw)->not->toContain('radiculopathy');

    foreach (['en', 'ar', 'ml'] as $lang) {
        $pdf = actingAsStaff($this->owner)->get("/api/prescriptions/{$res->json('data.id')}/pdf?lang={$lang}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($pdf->getContent())->toStartWith('%PDF');
    }
});

it('lets only the prescribing physiotherapist edit, and hides prescriptions from non-clinical roles', function () {
    $id = actingAsStaff($this->therapist)->postJson("/api/patients/{$this->patient->id}/prescriptions", ['advice' => 'Ice'])->json('data.id');
    $other = staff($this->tenant, Role::Therapist);

    actingAsStaff($other)->putJson("/api/prescriptions/{$id}", ['advice' => 'Heat'])->assertForbidden();
    actingAsStaff($this->therapist)->putJson("/api/prescriptions/{$id}", ['advice' => 'Heat'])->assertOk()->assertJsonPath('data.advice', 'Heat');
    actingAsStaff($this->desk)->getJson("/api/prescriptions/{$id}")->assertForbidden();
    actingAsStaff($this->desk)->getJson('/api/prescriptions')->assertForbidden();
});

it('lists consultations with their prescription link and monthly figures', function () {
    $assessmentId = assessmentFor($this);
    actingAsStaff($this->therapist)->getJson('/api/consultations')->assertOk()
        ->assertJsonPath('data.0.id', $assessmentId)
        ->assertJsonPath('data.0.prescription_id', null)
        ->assertJsonPath('summary.this_month', 1)
        ->assertJsonPath('summary.without_prescription', 1);

    $rx = actingAsStaff($this->therapist)->postJson("/api/patients/{$this->patient->id}/prescriptions", ['assessment_id' => $assessmentId])->json('data.id');
    actingAsStaff($this->therapist)->getJson('/api/consultations')
        ->assertJsonPath('data.0.prescription_id', $rx)
        ->assertJsonPath('summary.without_prescription', 0);
});

it('builds a patient timeline limited to what the viewer may see', function () {
    assessmentFor($this);
    $invoice = actingAsStaff($this->owner)->postJson('/api/invoices', [
        'patient_id' => $this->patient->id, 'branch_id' => $this->branch->id,
        'items' => [['service_id' => service($this->tenant)->id]],
    ])->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$invoice}/post")->assertOk();
    actingAsStaff($this->owner)->postJson("/api/invoices/{$invoice}/payments", ['amount_minor' => 30000, 'method' => 'cash'])->assertCreated();

    $owner = collect(actingAsStaff($this->owner)->getJson("/api/patients/{$this->patient->id}/timeline")->assertOk()->json('data'))->pluck('type');
    expect($owner)->toContain('registered', 'assessment', 'invoice', 'payment');

    // Front desk: billing yes, clinical no.
    $desk = collect(actingAsStaff($this->desk)->getJson("/api/patients/{$this->patient->id}/timeline")->json('data'))->pluck('type');
    expect($desk)->toContain('invoice', 'payment')->not->toContain('assessment');

    // Therapist: clinical yes, billing no.
    $therapist = collect(actingAsStaff($this->therapist)->getJson("/api/patients/{$this->patient->id}/timeline")->json('data'))->pluck('type');
    expect($therapist)->toContain('assessment')->not->toContain('invoice', 'payment');

    actingAsStaff($this->owner)->getJson("/api/patients/{$this->patient->id}/timeline?types[]=payment")
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.data.receipt_number', 'RCT-KOC-000001');
});

it('numbers receipts per branch and prints them', function () {
    $invoice = actingAsStaff($this->owner)->postJson('/api/invoices', [
        'patient_id' => $this->patient->id, 'branch_id' => $this->branch->id,
        'items' => [['service_id' => service($this->tenant)->id]],
    ])->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$invoice}/post");

    $first = actingAsStaff($this->owner)->postJson("/api/invoices/{$invoice}/payments", ['amount_minor' => 30000, 'method' => 'upi'])->json('data');
    $second = actingAsStaff($this->owner)->postJson("/api/invoices/{$invoice}/payments", ['amount_minor' => 20000, 'method' => 'cash'])->json('data');
    expect([$first['receipt_number'], $second['receipt_number']])->toBe(['RCT-KOC-000001', 'RCT-KOC-000002']);

    foreach (['en', 'ml'] as $lang) {
        expect(actingAsStaff($this->desk)->get("/api/payments/{$first['id']}/receipt?lang={$lang}")->assertOk()->getContent())->toStartWith('%PDF');
    }

    ['owner' => $otherOwner] = clinic();
    actingAsStaff($otherOwner)->get("/api/payments/{$first['id']}/receipt")->assertNotFound();
    expect(inTenant($this->tenant, fn () => Payment::query()->count()))->toBe(2);
});

it('lists sold packages with sessions left and expiry', function () {
    $plan = inTenant($this->tenant, fn () => PackagePlan::query()->firstOrFail());
    $invoice = actingAsStaff($this->owner)->postJson('/api/invoices', [
        'patient_id' => $this->patient->id, 'branch_id' => $this->branch->id, 'items' => [['package_plan_id' => $plan->id]],
    ])->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$invoice}/post")->assertOk();

    actingAsStaff($this->desk)->getJson('/api/packages')->assertOk()
        ->assertJsonPath('data.0.patient.id', $this->patient->id)
        ->assertJsonPath('data.0.sessions_remaining', $plan->sessions_count)
        ->assertJsonPath('summary.active', 1)
        ->assertJsonPath('summary.sessions_remaining', $plan->sessions_count);

    actingAsStaff($this->therapist)->getJson('/api/packages')->assertForbidden();
});
