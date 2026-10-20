<?php

use App\Models\Exercise;
use App\Models\PlatformAdmin;
use App\Models\Service;
use App\Models\Tenant;

beforeEach(function () {
    $this->admin = PlatformAdmin::query()->forceCreate(['name' => 'Ops', 'email' => 'ops@sysnac.test', 'password' => 'Secret123']);
});

it('signs console operators in on their own guard', function () {
    $this->postJson('/api/platform/auth/login', ['email' => 'ops@sysnac.test', 'password' => 'Secret123'])->assertOk()->assertJsonPath('data.email', 'ops@sysnac.test');
    $this->getJson('/api/platform/auth/me')->assertOk();
});

it('provisions a ready-to-use clinic', function () {
    $this->actingAs($this->admin, 'platform')->postJson('/api/platform/tenants', [
        'name' => 'Gulf Physio Kuwait', 'slug' => 'gulf-physio', 'country_code' => 'KW', 'currency_code' => 'KWD',
        'timezone' => 'Asia/Kuwait', 'plan' => 'premium', 'branch_name' => 'Salmiya', 'branch_code' => 'SAL',
        'owner_name' => 'Dr. Omar', 'owner_email' => 'omar@gulf.test', 'owner_password' => 'Secret123',
    ])->assertCreated()->assertJsonPath('data.slug', 'gulf-physio')->assertJsonPath('data.currency_code', 'KWD');

    $tenant = Tenant::query()->where('slug', 'gulf-physio')->firstOrFail();
    inTenant($tenant, function () {
        expect(Service::query()->count())->toBe(6)
            ->and(Exercise::query()->count())->toBeGreaterThan(20)
            ->and(Service::query()->where('code', 'ASSESS')->value('price_minor'))->toBe(30_000); // 30.000 KWD
    });

    // The new owner can sign straight into the clinic portal.
    freshBrowser()->postJson('/api/auth/login', ['email' => 'omar@gulf.test', 'password' => 'Secret123'])->assertOk()
        ->assertJsonPath('data.tenant.currency.exponent', 3);
});

it('validates slugs and owner emails', function () {
    clinic(['slug' => 'taken']);

    $this->actingAs($this->admin, 'platform')->postJson('/api/platform/tenants', [
        'name' => 'X', 'slug' => 'taken', 'country_code' => 'IN', 'currency_code' => 'INR', 'timezone' => 'Asia/Kolkata', 'plan' => 'standard',
        'branch_name' => 'Main', 'branch_code' => 'MAIN', 'owner_name' => 'X', 'owner_email' => 'not-an-email', 'owner_password' => 'short',
    ])->assertUnprocessable()->assertJsonValidationErrors(['slug', 'owner_email', 'owner_password']);
});

it('suspends and reactivates clinics', function () {
    ['tenant' => $tenant, 'owner' => $owner] = clinic();

    $this->actingAs($this->admin, 'platform')->putJson("/api/platform/tenants/{$tenant->id}", ['status' => 'suspended'])->assertOk();
    actingAsStaff($owner)->getJson('/api/auth/me')->assertForbidden();

    freshBrowser()->actingAs($this->admin, 'platform')->putJson("/api/platform/tenants/{$tenant->id}", ['status' => 'active'])->assertOk();
    actingAsStaff($owner)->getJson('/api/auth/me')->assertOk();
});

it('reports platform metrics and tenant details without patient data', function () {
    ['tenant' => $tenant] = clinic();
    patient($tenant);

    $this->actingAs($this->admin, 'platform')->getJson('/api/platform/metrics')->assertOk()
        ->assertJsonPath('data.tenants_total', 1)->assertJsonPath('data.patients_total', 1);

    $this->actingAs($this->admin, 'platform')->getJson('/api/platform/tenants')->assertOk()->assertJsonPath('data.0.patients_count', 1);

    $this->actingAs($this->admin, 'platform')->getJson("/api/platform/tenants/{$tenant->id}")->assertOk()
        ->assertJsonPath('data.stats.patients', 1)
        ->assertJsonMissingPath('data.patients');
});
