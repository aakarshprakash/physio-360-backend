<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Notifications\PortalInviteNotification;
use Carbon\Carbon;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->kochi] = clinic();
    $this->tvm = inTenant($this->tenant, fn () => Branch::query()->create(['name' => 'Trivandrum', 'code' => 'TVM']));
});

it('registers patients with sequential MRNs and validation', function () {
    $payload = ['first_name' => 'Anil', 'last_name' => 'Kumar', 'phone' => '+91 98765 43210', 'primary_branch_id' => $this->kochi->id, 'gender' => 'male', 'date_of_birth' => '1994-03-12'];

    actingAsStaff($this->owner)->postJson('/api/patients', $payload)->assertCreated()->assertJsonPath('data.mrn', 'P-1001')->assertJsonPath('data.age', Carbon::parse('1994-03-12')->age);
    actingAsStaff($this->owner)->postJson('/api/patients', $payload)->assertCreated()->assertJsonPath('data.mrn', 'P-1002');

    actingAsStaff($this->owner)->postJson('/api/patients', ['first_name' => '', 'phone' => 'abc', 'primary_branch_id' => 999, 'date_of_birth' => '2999-01-01'])
        ->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'phone', 'primary_branch_id', 'date_of_birth']);
});

it('restricts staff to patients of their branches', function () {
    $kochiTherapist = staff($this->tenant, Role::Therapist, [], [$this->kochi->id]);
    $tvmPatient = patient($this->tenant, ['primary_branch_id' => $this->tvm->id]);
    $kochiPatient = patient($this->tenant, ['primary_branch_id' => $this->kochi->id]);

    actingAsStaff($kochiTherapist)->getJson("/api/patients/{$tvmPatient->id}")->assertForbidden();
    actingAsStaff($kochiTherapist)->getJson("/api/patients/{$kochiPatient->id}")->assertOk();
    actingAsStaff($kochiTherapist)->getJson('/api/patients')->assertJsonCount(1, 'data');

    // Owners see every branch.
    actingAsStaff($this->owner)->getJson('/api/patients')->assertJsonCount(2, 'data');
});

it('lets only privileged roles archive patients', function () {
    $patient = patient($this->tenant);

    actingAsStaff(staff($this->tenant, Role::Receptionist))->deleteJson("/api/patients/{$patient->id}")->assertForbidden();
    actingAsStaff($this->owner)->deleteJson("/api/patients/{$patient->id}")->assertNoContent();
    actingAsStaff($this->owner)->getJson("/api/patients/{$patient->id}")->assertNotFound();
    $this->assertSoftDeleted('patients', ['id' => $patient->id]);
});

it('issues one-time patient app activation links', function () {
    Notification::fake();
    $patient = patient($this->tenant);

    $url = actingAsStaff($this->owner)->postJson("/api/patients/{$patient->id}/portal-invite")->assertOk()->json('data.activation_url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    expect($query['clinic'])->toBe($this->tenant->slug)->and(strlen($query['token']))->toBe(48);
    Notification::assertSentOnDemand(PortalInviteNotification::class);

    freshBrowser()->postJson('/api/patient/auth/activate', ['clinic' => $this->tenant->slug, 'token' => $query['token'], 'password' => 'Patient123', 'password_confirmation' => 'Patient123'])
        ->assertOk()->assertJsonPath('data.patient.id', $patient->id);

    freshBrowser()->postJson('/api/patient/auth/activate', ['clinic' => $this->tenant->slug, 'token' => $query['token'], 'password' => 'Patient123', 'password_confirmation' => 'Patient123'])
        ->assertUnprocessable();
});

it('enforces the module permission matrix', function (Role $role, string $method, string $uri, int $status) {
    $user = staff($this->tenant, $role);
    $uri = str_replace('{branch}', (string) $this->kochi->id, $uri);

    actingAsStaff($user)->json($method, $uri)->assertStatus($status);
})->with([
    'therapist cannot view reports' => [Role::Therapist, 'GET', '/api/reports/summary', 403],
    'accountant can view reports' => [Role::Accountant, 'GET', '/api/reports/summary', 200],
    'receptionist cannot manage staff' => [Role::Receptionist, 'POST', '/api/staff', 403],
    'receptionist cannot change settings' => [Role::Receptionist, 'PUT', '/api/settings/clinic', 403],
    'therapist cannot export patients' => [Role::Therapist, 'GET', '/api/exports/patients', 403],
    'admin cannot read audit log' => [Role::Admin, 'GET', '/api/audit-logs', 403],
    'accountant cannot write clinical' => [Role::Accountant, 'GET', '/api/treatment-plans', 403],
    'receptionist can see the schedule' => [Role::Receptionist, 'GET', '/api/calendar/resources?branch_id={branch}', 200],
]);

it('hides revenue from staff without billing or report access', function () {
    $therapist = staff($this->tenant, Role::Therapist);

    actingAsStaff($therapist)->getJson('/api/dashboard')->assertOk()
        ->assertJsonMissingPath('data.kpis.revenue_month_minor')
        ->assertJsonMissingPath('data.revenue_trend');

    actingAsStaff($this->owner)->getJson('/api/dashboard')->assertOk()
        ->assertJsonStructure(['data' => ['kpis' => ['today_appointments', 'active_patients', 'revenue_month_minor', 'pending_payments', 'patient_progress'], 'today', 'revenue_trend']]);
});
