<?php

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\OnlineBooking;
use App\Models\Patient;
use App\Models\Tenant;
use App\Services\Hr\Geofence;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->branch] = clinic();
    $this->therapist = staff($this->tenant, Role::Therapist, ['name' => 'Dr. Arjun']);
    $this->desk = staff($this->tenant, Role::Receptionist);
    $this->service = service($this->tenant);
    $this->slug = $this->tenant->slug;
});

function setClinicSettings(Tenant $tenant, array $settings): void
{
    $tenant->forceFill(['settings' => array_replace_recursive($tenant->fresh()->settings ?? [], $settings)])->save();
}

function firstSlot(object $t, ?string $date = null, array $query = []): string
{
    $date ??= nextWorkday()->toDateString();
    $slots = freshBrowser()->getJson("/api/public/clinics/{$t->slug}/booking/slots?".http_build_query(array_merge([
        'branch_id' => $t->branch->id, 'service_id' => $t->service->id, 'date' => $date,
    ], $query)))->assertOk()->json('data');
    expect($slots)->not->toBeEmpty();

    return $slots[0]['local_start'];
}

function bookingPayload(object $t, string $start, array $overrides = []): array
{
    return array_merge([
        'branch_id' => $t->branch->id, 'service_id' => $t->service->id, 'local_start' => $start,
        'name' => 'Lakshmi Menon', 'phone' => '+91 98470 22222', 'email' => 'lakshmi@example.test', 'age' => 42,
        'gender' => 'female', 'message' => 'Lower back pain for 2 weeks', 'source' => 'whatsapp',
    ], $overrides);
}

// ---- Public booking page ----------------------------------------------------

it('publishes the clinic booking page without leaking private data', function () {
    $page = freshBrowser()->getJson("/api/public/clinics/{$this->slug}/booking")->assertOk();
    expect($page->json('data.settings.mode'))->toBe('request')
        ->and(collect($page->json('data.practitioners'))->pluck('name'))->toContain('Dr. Arjun')
        ->and($page->json('data.practitioners.0'))->not->toHaveKeys(['email', 'phone'])
        ->and(collect($page->json('data.services'))->pluck('id'))->toContain($this->service->id);

    freshBrowser()->getJson('/api/public/clinics/no-such-clinic/booking')->assertNotFound();
});

it('offers merged "any therapist" slots within the booking window', function () {
    $start = firstSlot($this);
    expect($start)->toStartWith(nextWorkday()->toDateString());

    $tooFar = CarbonImmutable::now('Asia/Kolkata')->addDays(60)->toDateString();
    freshBrowser()->getJson("/api/public/clinics/{$this->slug}/booking/slots?branch_id={$this->branch->id}&service_id={$this->service->id}&date={$tooFar}")
        ->assertOk()->assertJsonCount(0, 'data');
});

it('records a request that the front desk confirms into a patient and appointment', function () {
    $start = firstSlot($this);
    $res = freshBrowser()->postJson("/api/public/clinics/{$this->slug}/booking", bookingPayload($this, $start))->assertCreated()
        ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.local_start', $start);
    expect($res->json('data.reference'))->toStartWith('BK-');

    $inbox = actingAsStaff($this->desk)->getJson('/api/online-bookings')->assertOk()->assertJsonPath('meta.counts.pending', 1);
    $id = $inbox->json('data.0.id');
    expect($inbox->json('data.0.phone'))->toBe('+91 98470 22222')->and($inbox->json('data.0.source'))->toBe('whatsapp');

    actingAsStaff($this->therapist)->getJson('/api/online-bookings')->assertOk(); // therapists manage appointments too
    actingAsStaff($this->desk)->postJson("/api/online-bookings/{$id}/confirm")->assertOk()
        ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.patient.full_name', 'Lakshmi Menon');

    inTenant($this->tenant, function () use ($start) {
        $appointment = Appointment::query()->latest('id')->firstOrFail();
        expect($appointment->booked_via)->toBe('online')
            ->and($appointment->practitioner_id)->toBe($this->therapist->id)
            ->and($appointment->local_starts_at->format('Y-m-d\TH:i'))->toBe($start)
            ->and(Patient::query()->where('referred_by', 'like', 'Online booking%')->count())->toBe(1);
    });

    actingAsStaff($this->desk)->postJson("/api/online-bookings/{$id}/confirm")->assertUnprocessable()->assertJsonPath('code', 'request_closed');
});

it('links a confirmed request to the existing patient with the same phone and name', function () {
    $existing = patient($this->tenant, ['first_name' => 'Lakshmi', 'last_name' => 'Menon', 'phone' => '+919847022222']);
    freshBrowser()->postJson("/api/public/clinics/{$this->slug}/booking", bookingPayload($this, firstSlot($this)))->assertCreated();
    $id = inTenant($this->tenant, fn () => OnlineBooking::query()->value('id'));

    actingAsStaff($this->desk)->getJson("/api/online-bookings/{$id}/matches")->assertOk()->assertJsonPath('data.0.id', $existing->id);
    actingAsStaff($this->desk)->postJson("/api/online-bookings/{$id}/confirm")->assertOk()->assertJsonPath('data.patient.id', $existing->id);
    expect(inTenant($this->tenant, fn () => Patient::query()->count()))->toBe(1);
});

it('books instantly when the clinic allows it, and refuses taken or disabled slots', function () {
    setClinicSettings($this->tenant, ['online_booking' => ['mode' => 'instant']]);
    $start = firstSlot($this, null, ['practitioner_id' => $this->therapist->id]);

    freshBrowser()->postJson("/api/public/clinics/{$this->slug}/booking", bookingPayload($this, $start, ['practitioner_id' => $this->therapist->id]))
        ->assertCreated()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.practitioner', 'Dr. Arjun');

    // The slot is gone now (only one practitioner).
    freshBrowser()->postJson("/api/public/clinics/{$this->slug}/booking", bookingPayload($this, $start, ['practitioner_id' => $this->therapist->id, 'phone' => '+91 90000 11111']))
        ->assertUnprocessable()->assertJsonPath('code', 'slot_unavailable');

    setClinicSettings($this->tenant, ['online_booking' => ['enabled' => false]]);
    freshBrowser()->postJson("/api/public/clinics/{$this->slug}/booking", bookingPayload($this, $start))->assertUnprocessable()->assertJsonPath('code', 'booking_disabled');
});

it('hides non-bookable services and practitioners and rejects bots', function () {
    inTenant($this->tenant, fn () => $this->service->forceFill(['online_bookable' => false])->save());
    $page = freshBrowser()->getJson("/api/public/clinics/{$this->slug}/booking")->json('data');
    expect(collect($page['services'])->pluck('id'))->not->toContain($this->service->id);

    inTenant($this->tenant, fn () => $this->service->forceFill(['online_bookable' => true])->save());
    freshBrowser()->postJson("/api/public/clinics/{$this->slug}/booking", bookingPayload($this, firstSlot($this), ['website' => 'http://spam']))
        ->assertUnprocessable()->assertJsonValidationErrors('website');
});

it('lets the front desk decline a request', function () {
    freshBrowser()->postJson("/api/public/clinics/{$this->slug}/booking", bookingPayload($this, firstSlot($this)))->assertCreated();
    $id = inTenant($this->tenant, fn () => OnlineBooking::query()->value('id'));
    actingAsStaff($this->desk)->postJson("/api/online-bookings/{$id}/decline", ['note' => 'Therapist unavailable, called patient'])->assertOk()->assertJsonPath('data.status', 'declined');
    actingAsStaff($this->desk)->getJson('/api/online-bookings')->assertJsonPath('meta.counts.declined', 1)->assertJsonCount(0, 'data');
});

// ---- Geofenced attendance ------------------------------------------------------

it('measures distance on the globe', function () {
    // Kochi MG Road to Ernakulam South station is roughly 1.1 km.
    expect(Geofence::distance(9.9658, 76.2875, 9.9690, 76.2970))->toBeBetween(1000, 1200);
});

it('enforces the branch geofence on clock-in when the clinic turns it on', function () {
    actingAsStaff($this->owner)->putJson("/api/settings/branches/{$this->branch->id}", ['latitude' => 9.9658, 'longitude' => 76.2875, 'geofence_radius_m' => 150])
        ->assertOk()->assertJsonPath('data.geofence_radius_m', 150);
    setClinicSettings($this->tenant, ['attendance_geofence' => true]);

    actingAsStaff($this->therapist)->getJson('/api/attendance/today')->assertOk()
        ->assertJsonPath('data.geofence.required', true)->assertJsonPath('data.geofence.sites.0.radius_m', 150);

    actingAsStaff($this->therapist)->postJson('/api/attendance/check-in')->assertUnprocessable()->assertJsonPath('code', 'location_required');
    actingAsStaff($this->therapist)->postJson('/api/attendance/check-in', ['latitude' => 9.9690, 'longitude' => 76.2970, 'accuracy' => 20])
        ->assertUnprocessable()->assertJsonPath('code', 'outside_geofence')->assertJsonPath('context.radius_m', 150);

    actingAsStaff($this->therapist)->postJson('/api/attendance/check-in', ['latitude' => 9.9662, 'longitude' => 76.2878, 'accuracy' => 12, 'device' => 'android'])
        ->assertOk()->assertJsonPath('data.can_check_out', true)->assertJsonPath('data.check_in_distance_m', fn ($d) => $d < 100);
    actingAsStaff($this->therapist)->postJson('/api/attendance/check-out', ['latitude' => 9.9690, 'longitude' => 76.2970])->assertUnprocessable()->assertJsonPath('code', 'outside_geofence');
});

it('never blocks geofence-exempt staff but still records where they clocked in', function () {
    actingAsStaff($this->owner)->putJson("/api/settings/branches/{$this->branch->id}", ['latitude' => 9.9658, 'longitude' => 76.2875])->assertOk();
    setClinicSettings($this->tenant, ['attendance_geofence' => true]);
    actingAsStaff($this->owner)->putJson("/api/staff/{$this->therapist->id}", ['geofence_exempt' => true])->assertOk();

    actingAsStaff($this->therapist->fresh())->postJson('/api/attendance/check-in', ['latitude' => 10.5, 'longitude' => 76.2])->assertOk()
        ->assertJsonPath('data.check_in_distance_m', fn ($d) => $d > 50000);
});

it('records location without enforcing when the geofence is off', function () {
    actingAsStaff($this->therapist)->postJson('/api/attendance/check-in')->assertOk();
});

// ---- Patient photo, clinic logo, invoice -------------------------------------------

it('stores a patient photo privately and serves it to staff only', function () {
    Storage::fake(config('filesystems.private_disk'));
    $patient = patient($this->tenant);

    $url = actingAsStaff($this->therapist)->post("/api/patients/{$patient->id}/photo", ['photo' => UploadedFile::fake()->image('face.jpg', 400, 400)], ['Accept' => 'application/json'])
        ->assertOk()->json('data.photo_url');
    expect($url)->toStartWith("/api/patients/{$patient->id}/photo");

    actingAsStaff($this->desk)->get($url)->assertOk();
    actingAsStaff($this->desk)->getJson("/api/patients/{$patient->id}")->assertJsonPath('data.photo_url', $url);
    freshBrowser()->getJson($url)->assertUnauthorized();

    actingAsStaff($this->desk)->deleteJson("/api/patients/{$patient->id}/photo")->assertNoContent();
    actingAsStaff($this->desk)->getJson("/api/patients/{$patient->id}")->assertJsonPath('data.photo_url', null);
});

it('uploads a clinic logo used by the booking page and PDFs', function () {
    Storage::fake(config('filesystems.private_disk'));
    actingAsStaff($this->desk)->post('/api/settings/clinic/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 120)], ['Accept' => 'application/json'])->assertForbidden();

    $logo = actingAsStaff($this->owner)->post('/api/settings/clinic/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 120)], ['Accept' => 'application/json'])
        ->assertOk()->json('data.logo_url');
    expect($logo)->toContain("/api/public/clinics/{$this->slug}/logo");
    freshBrowser()->get("/api/public/clinics/{$this->slug}/logo")->assertOk();
    freshBrowser()->getJson("/api/public/clinics/{$this->slug}/booking")->assertJsonPath('data.clinic.logo_url', $logo);
});

it('saves invoice letterhead settings', function () {
    actingAsStaff($this->owner)->putJson('/api/settings/clinic', ['settings' => ['invoice' => ['upi_id' => 'clinic@okhdfc', 'terms' => 'Due in 7 days', 'signatory' => 'Dr. Sarah Mathew']]])
        ->assertOk()->assertJsonPath('data.settings.invoice.upi_id', 'clinic@okhdfc');
    actingAsStaff($this->owner)->putJson('/api/settings/clinic', ['settings' => ['invoice' => ['upi_id' => 'not a upi']]])->assertUnprocessable();
});
