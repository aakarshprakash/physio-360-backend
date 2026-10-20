<?php

use App\Enums\Role;
use App\Exceptions\ImmutableRecord;
use App\Models\ClinicalNote;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->branch] = clinic();
    $this->therapist = staff($this->tenant, Role::Therapist);
    $this->receptionist = staff($this->tenant, Role::Receptionist);
    $this->patient = patient($this->tenant, ['medical_history' => 'ACL reconstruction 2026']);
});

function note(array $data = []): TestResponse
{
    $t = test();

    return actingAsStaff($t->therapist)->postJson("/api/patients/{$t->patient->id}/notes", array_merge([
        'subjective' => 'Pain on stairs', 'objective' => 'Flexion 100°', 'assessment' => 'Improving', 'plan' => 'Progress HEP', 'pain_score' => 5,
    ], $data));
}

it('creates draft SOAP notes that the author can edit', function () {
    $id = note()->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    actingAsStaff($this->therapist)->putJson("/api/notes/{$id}", ['plan' => 'Add step-ups'])->assertOk()->assertJsonPath('data.plan', 'Add step-ups');
});

it('stops other clinicians editing someone else\'s draft', function () {
    $id = note()->json('data.id');
    $colleague = staff($this->tenant, Role::Therapist);

    actingAsStaff($colleague)->putJson("/api/notes/{$id}", ['plan' => 'x'])->assertForbidden();
    actingAsStaff($colleague)->postJson("/api/notes/{$id}/sign")->assertForbidden();
});

it('makes signed notes immutable at every layer', function () {
    $id = note()->json('data.id');
    actingAsStaff($this->therapist)->postJson("/api/notes/{$id}/sign")->assertOk()
        ->assertJsonPath('data.status', 'signed')
        ->assertJson(fn ($json) => $json->whereType('data.content_hash', 'string')->etc());

    // HTTP: policy refuses edits and deletes.
    actingAsStaff($this->therapist)->putJson("/api/notes/{$id}", ['plan' => 'tamper'])->assertForbidden();
    actingAsStaff($this->therapist)->deleteJson("/api/notes/{$id}")->assertForbidden();

    // Model: refuses even for code paths that bypass the policy.
    expect(fn () => inTenant($this->tenant, fn () => ClinicalNote::query()->find($id)->update(['plan' => 'tamper'])))
        ->toThrow(ImmutableRecord::class);

    // Database: trigger rejects raw SQL.
    expect(fn () => DB::table('clinical_notes')->where('id', $id)->update(['plan' => 'tamper']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('clinical_notes')->where('id', $id)->delete())->toThrow(QueryException::class);
});

it('allows addenda on signed notes only', function () {
    $id = note()->json('data.id');
    actingAsStaff($this->therapist)->postJson("/api/notes/{$id}/addenda", ['body' => 'Too early'])->assertForbidden();

    actingAsStaff($this->therapist)->postJson("/api/notes/{$id}/sign")->assertOk();
    actingAsStaff($this->therapist)->postJson("/api/notes/{$id}/addenda", ['body' => 'Correction: flexion was 105°'])
        ->assertOk()->assertJsonPath('data.addenda.0.body', 'Correction: flexion was 105°');

    expect(fn () => DB::table('clinical_note_addenda')->update(['body' => 'x']))->toThrow(QueryException::class);
});

it('refuses to sign an empty note', function () {
    $id = actingAsStaff($this->therapist)->postJson("/api/patients/{$this->patient->id}/notes", ['pain_score' => 3])->json('data.id');

    actingAsStaff($this->therapist)->postJson("/api/notes/{$id}/sign")->assertUnprocessable()->assertJsonPath('code', 'empty_note');
});

it('hides clinical records and medical fields from the front desk', function () {
    note();

    actingAsStaff($this->receptionist)->getJson("/api/patients/{$this->patient->id}/notes")->assertForbidden();
    actingAsStaff($this->receptionist)->postJson("/api/patients/{$this->patient->id}/notes", ['subjective' => 'x'])->assertForbidden();
    actingAsStaff($this->receptionist)->getJson("/api/patients/{$this->patient->id}")->assertOk()->assertJsonMissingPath('data.medical_history');

    actingAsStaff($this->therapist)->getJson("/api/patients/{$this->patient->id}")->assertJsonPath('data.medical_history', 'ACL reconstruction 2026');
});

it('records pain scores from notes on the progress chart', function () {
    note(['pain_score' => 6]);
    note(['pain_score' => 3]);

    actingAsStaff($this->therapist)->getJson("/api/patients/{$this->patient->id}/progress")->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('data.1.pain_score', 3);
});

it('stores structured assessments and signs them', function () {
    $id = actingAsStaff($this->therapist)->postJson("/api/patients/{$this->patient->id}/assessments", [
        'chief_complaint' => 'Right knee pain',
        'pain_score' => 7,
        'findings' => [
            'rom' => [['joint' => 'Knee (R)', 'movement' => 'Flexion', 'degrees' => 95]],
            'special_tests' => [['name' => 'Lachman', 'result' => 'negative']],
        ],
        'sign' => true,
    ])->assertCreated()->assertJsonPath('data.status', 'signed')->assertJsonPath('data.findings.rom.0.degrees', 95)->json('data.id');

    actingAsStaff($this->therapist)->putJson("/api/assessments/{$id}", ['diagnosis' => 'x'])->assertForbidden();
});

it('encrypts PHI at rest and finds patients by blind index', function () {
    $row = DB::table('patients')->where('id', $this->patient->id)->first();

    expect($row->phone)->not->toContain('98765')
        ->and($row->medical_history)->not->toContain('ACL')
        ->and($row->phone_index)->toHaveLength(64);

    $digits = substr(preg_replace('/\D/', '', $this->patient->phone), -10);
    actingAsStaff($this->owner)->getJson('/api/patients?search=0'.$digits)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->patient->id);

    actingAsStaff($this->owner)->getJson('/api/patients?search='.strtoupper($this->patient->email))->assertJsonCount(1, 'data');
    actingAsStaff($this->owner)->getJson('/api/patients?search='.$this->patient->mrn)->assertJsonCount(1, 'data');
});

it('writes an append-only audit trail without leaking PHI', function () {
    actingAsStaff($this->owner)->putJson("/api/patients/{$this->patient->id}", ['medical_history' => 'Updated history', 'city' => 'Kochi'])->assertOk();

    $log = DB::table('audit_logs')->where('auditable_type', 'Patient')->where('event', 'updated')->latest('id')->first();
    $new = json_decode($log->new_values, true);

    expect($new['medical_history'])->toBe('[redacted]')
        ->and($new['city'])->toBe('Kochi')
        ->and($log->actor_id)->toBe($this->owner->id)
        ->and(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['event' => 'x']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('audit_logs')->where('id', $log->id)->delete())->toThrow(QueryException::class);

    actingAsStaff($this->owner)->getJson('/api/audit-logs?auditable_type=Patient')->assertOk()->assertJsonPath('data.0.event', 'updated');
    actingAsStaff($this->therapist)->getJson('/api/audit-logs')->assertForbidden();
});

it('builds treatment plans and prints the exercise prescription', function () {
    $exercises = actingAsStaff($this->therapist)->getJson('/api/exercises?body_region=knee')->assertOk()->json('data');

    $planId = actingAsStaff($this->therapist)->postJson("/api/patients/{$this->patient->id}/treatment-plans", [
        'title' => 'Knee Rehabilitation Program',
        'start_date' => now()->subDays(15)->toDateString(),
        'duration_weeks' => 6,
        'exercises' => [['exercise_id' => $exercises[0]['id'], 'sets' => 3, 'reps' => 15], ['exercise_id' => $exercises[1]['id']]],
    ])->assertCreated()
        ->assertJsonPath('data.current_week', 3)
        ->assertJsonCount(2, 'data.exercises')
        ->assertJsonPath('data.exercises.1.sets', $exercises[1]['default_sets'])
        ->json('data.id');

    actingAsStaff($this->therapist)->postJson("/api/treatment-plans/{$planId}/exercises", ['exercise_id' => $exercises[2]['id'], 'frequency' => '2x daily'])
        ->assertCreated()->assertJsonPath('data.frequency', '2x daily');

    $pdf = actingAsStaff($this->therapist)->get("/api/treatment-plans/{$planId}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF');

    actingAsStaff($this->receptionist)->get("/api/treatment-plans/{$planId}")->assertForbidden();
});

it('stores documents privately and streams them only to clinical staff', function () {
    Storage::fake('local');

    $id = actingAsStaff($this->therapist)->post("/api/patients/{$this->patient->id}/documents", [
        'file' => UploadedFile::fake()->create('mri-report.pdf', 300, 'application/pdf'),
        'category' => 'imaging',
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.original_name', 'mri-report.pdf')->json('data.id');

    $path = DB::table('documents')->where('id', $id)->value('path');
    Storage::disk('local')->assertExists($path);
    expect($path)->toStartWith("tenants/{$this->tenant->id}/patients/{$this->patient->id}/")->not->toContain('mri-report');

    actingAsStaff($this->therapist)->get("/api/documents/{$id}/download")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    actingAsStaff($this->receptionist)->get("/api/documents/{$id}/download")->assertForbidden();

    actingAsStaff($this->therapist)->post("/api/patients/{$this->patient->id}/documents", [
        'file' => UploadedFile::fake()->create('virus.exe', 10), 'category' => 'other',
    ], ['Accept' => 'application/json'])->assertUnprocessable();
});

it('captures e-signed consent in clinic with a witness', function () {
    Storage::fake('local');
    $template = actingAsStaff($this->owner)->getJson("/api/patients/{$this->patient->id}/consents")->assertOk()->json('data.pending.0');

    actingAsStaff($this->receptionist)->postJson("/api/patients/{$this->patient->id}/consents", [
        'consent_template_id' => $template['id'],
        'signature' => signaturePng(),
        'signer_name' => 'Test Patient',
        'signer_relationship' => 'self',
    ])->assertCreated()->assertJsonPath('data.witness', $this->receptionist->name)->assertJsonPath('data.body_snapshot', $template['body']);

    actingAsStaff($this->owner)->getJson("/api/patients/{$this->patient->id}/consents")
        ->assertJsonCount(1, 'data.signed')
        ->assertJson(fn ($json) => $json->where('data.pending', fn ($p) => collect($p)->doesntContain('id', $template['id']))->etc());

    // Editing the template text bumps its version and asks for a new signature.
    actingAsStaff($this->owner)->putJson("/api/settings/consent-templates/{$template['id']}", ['body' => 'New wording'])->assertJsonPath('data.version', 2);
    actingAsStaff($this->owner)->getJson("/api/patients/{$this->patient->id}/consents")
        ->assertJson(fn ($json) => $json->where('data.pending', fn ($p) => collect($p)->contains('id', $template['id']))->etc());
});

it('rejects forged signature payloads', function () {
    $template = actingAsStaff($this->owner)->getJson("/api/patients/{$this->patient->id}/consents")->json('data.pending.0');

    actingAsStaff($this->owner)->postJson("/api/patients/{$this->patient->id}/consents", [
        'consent_template_id' => $template['id'], 'signature' => 'data:image/png;base64,'.base64_encode('<script>'),
        'signer_name' => 'X', 'signer_relationship' => 'self',
    ])->assertUnprocessable()->assertJsonPath('code', 'invalid_signature');
});
