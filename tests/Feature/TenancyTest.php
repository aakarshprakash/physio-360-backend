<?php

use App\Enums\Role;
use App\Jobs\SendAppointmentConfirmation;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Patient;
use App\Services\Scheduling\BookingService;
use App\Tenancy\CrossTenantWrite;
use App\Tenancy\MissingTenantContext;
use App\Tenancy\TenantContext;
use App\Tenancy\WithTenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    ['tenant' => $this->a, 'owner' => $this->ownerA] = clinic();
    ['tenant' => $this->b, 'owner' => $this->ownerB] = clinic();
    $this->patientA = patient($this->a, ['first_name' => 'Alice']);
    $this->patientB = patient($this->b, ['first_name' => 'Bob']);
});

it('scopes every query to the current tenant', function () {
    inTenant($this->a, fn () => expect(Patient::query()->pluck('first_name')->all())->toBe(['Alice']));
    inTenant($this->b, fn () => expect(Patient::query()->pluck('first_name')->all())->toBe(['Bob']));
});

it('fails closed when no tenant context is set', function () {
    expect(app(TenantContext::class)->has())->toBeFalse()
        ->and(Patient::query()->count())->toBe(0)
        ->and(Patient::query()->find($this->patientA->id))->toBeNull();
});

it('refuses to create tenant-owned rows without a tenant', function () {
    Patient::query()->create(['first_name' => 'Orphan', 'primary_branch_id' => 1]);
})->throws(MissingTenantContext::class);

it('refuses to write rows for another tenant', function () {
    inTenant($this->a, function () {
        $p = new Patient(['first_name' => 'Sneaky', 'primary_branch_id' => 1]);
        $p->tenant_id = $this->b->id;
        $p->mrn = 'X-1';
        $p->save();
    });
})->throws(CrossTenantWrite::class);

it('returns 404 for another clinic\'s records over the API', function () {
    actingAsStaff($this->ownerA)->getJson("/api/patients/{$this->patientB->id}")->assertNotFound();
    actingAsStaff($this->ownerA)->getJson("/api/patients/{$this->patientA->id}")->assertOk()->assertJsonPath('data.first_name', 'Alice');
});

it('never lists other tenants\' patients, even by exact phone search', function () {
    $phone = $this->patientB->phone;

    actingAsStaff($this->ownerA)->getJson('/api/patients?search='.urlencode($phone))
        ->assertOk()->assertJsonCount(0, 'data');
});

it('makes cross-tenant references impossible at the database level', function () {
    // Composite FK (tenant_id, patient_id) -> patients(tenant_id, id): a row in A cannot point at B's patient.
    $branchA = inTenant($this->a, fn () => Branch::query()->value('id'));

    expect(fn () => DB::table('appointments')->insert([
        'tenant_id' => $this->a->id,
        'branch_id' => $branchA,
        'patient_id' => $this->patientB->id,
        'practitioner_id' => $this->ownerA->id,
        'starts_at' => now(), 'ends_at' => now()->addHour(),
        'blocked_from' => now(), 'blocked_until' => now()->addHour(),
        'local_starts_at' => now(), 'timezone' => 'UTC',
    ]))->toThrow(QueryException::class);
});

it('carries the tenant into queued jobs', function () {
    $appointment = inTenant($this->a, function () {
        $practitioner = staff($this->a, Role::Therapist);

        return app(BookingService::class)->book(bookingRequest($this->patientA, $practitioner, nextWorkday()));
    });

    $job = inTenant($this->a, fn () => new SendAppointmentConfirmation($appointment->id));
    expect($job->tenantId)->toBe($this->a->id)
        ->and($job->middleware()[0])->toBeInstanceOf(WithTenantContext::class);

    // Simulate a worker: no ambient context; the job middleware must establish it.
    $seen = null;
    (new WithTenantContext($job->tenantId))->handle($job, function () use (&$seen) {
        $seen = Appointment::query()->count();
    });
    expect($seen)->toBe(1)->and(app(TenantContext::class)->has())->toBeFalse();
});

it('namespaces cache keys per tenant', function () {
    $ka = inTenant($this->a, fn () => app(TenantContext::class)->cacheKey('dashboard'));
    $kb = inTenant($this->b, fn () => app(TenantContext::class)->cacheKey('dashboard'));

    expect($ka)->not->toBe($kb)->and($ka)->toStartWith('t:'.$this->a->id.':');
});

it('blocks staff of a suspended clinic', function () {
    $this->a->update(['status' => 'suspended']);

    actingAsStaff($this->ownerA)->getJson('/api/auth/me')->assertForbidden()->assertJsonPath('code', 'tenant_suspended');
});
