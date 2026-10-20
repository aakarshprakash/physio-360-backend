<?php

use App\Enums\Role;
use App\Models\PlatformAdmin;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner] = clinic();
});

it('signs staff in with a session and returns their permissions', function () {
    $this->postJson('/api/auth/login', ['email' => strtoupper($this->owner->email), 'password' => 'Secret123'])
        ->assertOk()
        ->assertJsonPath('data.user.email', $this->owner->email)
        ->assertJsonPath('data.tenant.currency.exponent', 2)
        ->assertJsonPath('data.tenant.slug', $this->tenant->slug)
        ->assertJson(fn ($json) => $json->where('data.permissions', fn ($p) => collect($p)->contains('audit.view'))->etc());

    expect(Auth::guard('web')->check())->toBeTrue()
        ->and(session('authenticated_at'))->toBeInt();

    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.user.id', $this->owner->id);
});

it('rejects bad credentials without revealing which part was wrong', function () {
    $this->postJson('/api/auth/login', ['email' => $this->owner->email, 'password' => 'nope'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->postJson('/api/auth/login', ['email' => 'nobody@example.test', 'password' => 'nope'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('throttles repeated login attempts', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/auth/login', ['email' => $this->owner->email, 'password' => 'wrong'])->assertUnprocessable();
    }
    $this->postJson('/api/auth/login', ['email' => $this->owner->email, 'password' => 'wrong'])->assertStatus(429);
});

it('refuses deactivated staff', function () {
    $user = staff($this->tenant, Role::Receptionist, ['is_active' => false]);

    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Secret123'])->assertUnprocessable();
});

it('requires authentication for staff endpoints', function () {
    $this->getJson('/api/patients')->assertUnauthorized();
    $this->getJson('/api/auth/me')->assertUnauthorized();
});

it('keeps patient, staff and platform identities on separate guards', function () {
    $patient = patient($this->tenant);
    $admin = PlatformAdmin::query()->forceCreate(['name' => 'Ops', 'email' => 'ops@example.test', 'password' => 'Secret123']);

    actingAsPatient($patient)->getJson('/api/patients')->assertUnauthorized();
    actingAsPatient($patient)->getJson('/api/platform/tenants')->assertUnauthorized();

    app('auth')->forgetGuards(); // fresh "browser"
    $this->actingAs($admin, 'platform')->getJson('/api/patients')->assertUnauthorized();
    $this->actingAs($admin, 'platform')->getJson('/api/patient/me')->assertUnauthorized();
});

it('rejects staff from the patient app and the SYSNAC console', function () {
    actingAsStaff($this->owner)->getJson('/api/patient/me')->assertUnauthorized();
    actingAsStaff($this->owner)->getJson('/api/platform/tenants')->assertUnauthorized();
});

it('expires sessions after the absolute timeout even when active', function () {
    config(['auth.absolute_timeout' => 60]);

    actingAsStaff($this->owner)
        ->withSession(['authenticated_at' => time() - 61 * 60])
        ->getJson('/api/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'session_expired');
});

it('lets staff change their password with the current one', function () {
    actingAsStaff($this->owner)->putJson('/api/auth/password', ['current_password' => 'wrong', 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456'])
        ->assertUnprocessable();
    actingAsStaff($this->owner)->putJson('/api/auth/password', ['current_password' => 'Secret123', 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456'])
        ->assertOk();

    expect(Hash::check('NewSecret456', $this->owner->fresh()->password))->toBeTrue();
});

it('sends password reset links without account enumeration', function () {
    Notification::fake();

    $this->postJson('/api/auth/forgot-password', ['email' => $this->owner->email])->assertOk();
    $this->postJson('/api/auth/forgot-password', ['email' => 'ghost@example.test'])->assertOk()
        ->assertJsonPath('message', 'If that address belongs to an account, a reset link has been sent.');

    Notification::assertSentTo($this->owner, ResetPassword::class);
});

it('writes a login entry to the audit log', function () {
    $this->postJson('/api/auth/login', ['email' => $this->owner->email, 'password' => 'Secret123'])->assertOk();

    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'event' => 'login', 'actor_id' => $this->owner->id, 'actor_type' => 'user']);
});

it('sends security headers and no-store on API responses', function () {
    actingAsStaff($this->owner)->getJson('/api/auth/me')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Cache-Control', 'no-store, private');
});
