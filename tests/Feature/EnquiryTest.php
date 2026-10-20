<?php

use App\Enums\Role;
use App\Models\Enquiry;
use App\Models\Patient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->branch] = clinic();
    $this->desk = staff($this->tenant, Role::Receptionist);
});

function enquiry(array $data = []): TestResponse
{
    $t = test();

    return actingAsStaff($t->desk)->postJson('/api/enquiries', array_merge([
        'branch_id' => $t->branch->id,
        'name' => 'Rahul Varma',
        'phone' => '+91 98470 22110',
        'source' => 'phone',
        'interest' => 'Lower back pain',
    ], $data));
}

it('captures enquiries with encrypted contact details', function () {
    $id = enquiry(['email' => 'rahul@example.com'])->assertCreated()
        ->assertJsonPath('data.status', 'new')
        ->assertJsonPath('data.phone', '+91 98470 22110')
        ->assertJsonPath('data.activities.0.body', 'Enquiry received via phone.')
        ->json('data.id');

    $row = DB::table('enquiries')->find($id);
    expect($row->phone)->not->toContain('98470')->and($row->email)->not->toContain('rahul');
});

it('moves through contact and follow-up as activity is logged', function () {
    $id = enquiry()->json('data.id');
    $tomorrow = now()->addDay()->setTime(11, 0)->toIso8601String();

    actingAsStaff($this->desk)->postJson("/api/enquiries/{$id}/activities", ['type' => 'call', 'body' => 'Explained fees'])
        ->assertOk()->assertJsonPath('data.status', 'contacted');

    actingAsStaff($this->desk)->postJson("/api/enquiries/{$id}/activities", ['type' => 'whatsapp', 'next_follow_up_at' => $tomorrow])
        ->assertOk()->assertJsonPath('data.status', 'follow_up')->assertJsonPath('data.is_overdue', false);

    actingAsStaff($this->desk)->postJson("/api/enquiries/{$id}/activities", ['type' => 'note'])->assertJsonValidationErrors('body');

    // Activity history is append-only at the database level.
    expect(fn () => DB::table('enquiry_activities')->update(['type' => 'x']))->toThrow(QueryException::class);
});

it('requires a reason to mark lost and can reopen', function () {
    $id = enquiry()->json('data.id');

    actingAsStaff($this->desk)->postJson("/api/enquiries/{$id}/status", ['status' => 'lost'])->assertUnprocessable()->assertJsonPath('code', 'reason_required');
    actingAsStaff($this->desk)->postJson("/api/enquiries/{$id}/status", ['status' => 'lost', 'reason' => 'Too expensive'])
        ->assertOk()->assertJsonPath('data.status', 'lost')->assertJsonPath('data.lost_reason', 'Too expensive');
    actingAsStaff($this->desk)->postJson("/api/enquiries/{$id}/activities", ['type' => 'call'])->assertUnprocessable()->assertJsonPath('code', 'enquiry_closed');
    actingAsStaff($this->desk)->postJson("/api/enquiries/{$id}/status", ['status' => 'new'])->assertOk()->assertJsonPath('data.lost_reason', null);
});

it('converts an enquiry into a registered patient exactly once', function () {
    $id = enquiry(['name' => 'Faisal Ahmed', 'email' => 'faisal@example.com'])->json('data.id');

    $res = actingAsStaff($this->desk)->postJson("/api/enquiries/{$id}/convert", ['gender' => 'male', 'date_of_birth' => '1990-01-01'])
        ->assertOk()
        ->assertJsonPath('data.status', 'converted')
        ->assertJsonPath('patient.first_name', 'Faisal')
        ->assertJsonPath('patient.last_name', 'Ahmed')
        ->assertJsonPath('patient.phone', '+91 98470 22110')
        ->assertJsonPath('patient.mrn', 'P-1001');

    expect($res->json('data.converted_patient.id'))->toBe($res->json('patient.id'));

    actingAsStaff($this->desk)->postJson("/api/enquiries/{$id}/convert")->assertUnprocessable()->assertJsonPath('code', 'enquiry_closed');
    actingAsStaff($this->desk)->postJson("/api/enquiries/{$id}/status", ['status' => 'new'])->assertUnprocessable();
});

it('flags existing patients with the same phone and can link instead of duplicating', function () {
    $existing = patient($this->tenant, ['phone' => '098470 22110']);

    $res = enquiry()->assertCreated()->assertJsonPath('matching_patients.0.id', $existing->id);

    actingAsStaff($this->desk)->postJson("/api/enquiries/{$res->json('data.id')}/convert", ['existing_patient_id' => $existing->id])
        ->assertOk()->assertJsonPath('patient.id', $existing->id);

    expect(inTenant($this->tenant, fn () => Patient::query()->count()))->toBe(1);
});

it('lists the pipeline with due follow-ups and conversion metrics', function () {
    $a = enquiry()->json('data.id');
    enquiry(['name' => 'Second', 'phone' => '+91 90000 11111']);
    inTenant($this->tenant, fn () => Enquiry::query()->whereKey($a)->update(['next_follow_up_at' => now()->subHour(), 'status' => 'follow_up']));

    actingAsStaff($this->desk)->getJson('/api/enquiries?status=due')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_overdue', true)
        ->assertJsonPath('summary.due_today', 1)
        ->assertJsonPath('summary.open', 2)
        ->assertJsonPath('summary.received_30d', 2);

    actingAsStaff($this->desk)->getJson('/api/enquiries?search=90000 11111')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Second');
});

it('is limited to roles with enquiry access and to the clinic', function () {
    $id = enquiry()->json('data.id');
    ['tenant' => $other, 'owner' => $otherOwner] = clinic();

    actingAsStaff(staff($this->tenant, Role::Therapist))->getJson('/api/enquiries')->assertForbidden();
    actingAsStaff($otherOwner)->getJson("/api/enquiries/{$id}")->assertNotFound();
    actingAsStaff($this->owner)->getJson("/api/enquiries/{$id}")->assertOk();
});

it('accepts website enquiries publicly with spam protection', function () {
    $url = "/api/public/clinics/{$this->tenant->slug}/enquiries";

    freshBrowser()->postJson($url, ['name' => 'Web Lead', 'phone' => '+91 99999 00000', 'interest' => 'Sciatica'])
        ->assertCreated()->assertJsonMissingPath('data');
    $this->postJson($url, ['name' => 'Bot', 'phone' => '+91 99999 00001', 'website' => 'http://spam'])->assertUnprocessable();
    $this->postJson('/api/public/clinics/nope/enquiries', ['name' => 'X', 'phone' => '+91 99999 00002'])->assertNotFound();

    inTenant($this->tenant, fn () => expect(Enquiry::query()->where('source', 'website')->value('name'))->toBe('Web Lead'));

    $this->postJson($url, ['name' => 'Again', 'phone' => '+91 99999 00003']);
    $this->postJson($url, ['name' => 'Again', 'phone' => '+91 99999 00004']);
    $this->postJson($url, ['name' => 'Again', 'phone' => '+91 99999 00005'])->assertStatus(429);
});
