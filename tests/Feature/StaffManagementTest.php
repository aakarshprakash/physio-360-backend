<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->branch] = clinic();
    $this->admin = staff($this->tenant, Role::Admin);
    $this->therapist = staff($this->tenant, Role::Therapist);
    $this->desk = staff($this->tenant, Role::Receptionist);
});

function newMember(object $t, array $overrides = []): array
{
    return array_merge([
        'name' => 'Dr. Meera Nair',
        'email' => 'meera-'.uniqid().'@example.test',
        'role' => 'therapist',
        'branch_ids' => [$t->branch->id],
        'employee_code' => 'EMP-0'.random_int(100, 999),
        'employment_type' => 'full_time',
        'joined_on' => '2026-01-15',
        'qualification' => 'MPT (Ortho)',
        'registration_number' => 'KSPC-12345',
        'emergency_contact_name' => 'Ravi Nair',
        'emergency_contact_phone' => '+91 98470 11111',
        'is_practitioner' => true,
    ], $overrides);
}

it('creates a team member with a generated password that must be changed at first sign-in', function () {
    $res = actingAsStaff($this->admin)->postJson('/api/staff', newMember($this) + ['credentials' => 'generate'])->assertCreated()
        ->assertJsonPath('data.employee_code', fn ($v) => str_starts_with($v, 'EMP-'))
        ->assertJsonPath('data.qualification', 'MPT (Ortho)')
        ->assertJsonPath('meta.credentials', 'generate');

    $temp = $res->json('meta.temporary_password');
    expect($temp)->toMatch('/^[A-Z][a-z]{5}-\d{4}$/');

    $user = User::query()->withoutGlobalScopes()->findOrFail($res->json('data.id'));
    expect(Hash::check($temp, $user->password))->toBeTrue()->and($user->must_change_password)->toBeTrue();

    // First sign-in reports the forced change; changing it clears the flag.
    freshBrowser()->postJson('/api/auth/login', ['email' => $user->email, 'password' => $temp])->assertOk()->assertJsonPath('data.must_change_password', true);
    actingAsStaff($user)->putJson('/api/auth/password', ['current_password' => $temp, 'password' => 'NewSecret99', 'password_confirmation' => 'NewSecret99'])->assertOk();
    expect($user->fresh()->must_change_password)->toBeFalse()->and($user->fresh()->password_changed_at)->not->toBeNull();
});

it('creates a team member with a password chosen by the admin, or an emailed invite', function () {
    actingAsStaff($this->admin)->postJson('/api/staff', newMember($this) + ['credentials' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');

    $res = actingAsStaff($this->admin)->postJson('/api/staff', newMember($this) + ['credentials' => 'password', 'password' => 'Welcome2026', 'password_confirmation' => 'Welcome2026'])
        ->assertCreated()->assertJsonPath('meta.temporary_password', null);
    expect(Hash::check('Welcome2026', User::query()->withoutGlobalScopes()->find($res->json('data.id'))->password))->toBeTrue();

    Notification::fake();
    config(['mail.default' => 'smtp']);
    $invite = actingAsStaff($this->admin)->postJson('/api/staff', newMember($this) + ['credentials' => 'invite'])->assertCreated()->assertJsonPath('meta.temporary_password', null);
    $invited = User::query()->withoutGlobalScopes()->find($invite->json('data.id'));
    expect($invited->must_change_password)->toBeFalse();
    Notification::assertSentTo($invited, Illuminate\Auth\Notifications\ResetPassword::class);
});

it('keeps employee codes unique within a clinic', function () {
    actingAsStaff($this->admin)->postJson('/api/staff', newMember($this, ['employee_code' => 'EMP-777']))->assertCreated();
    actingAsStaff($this->admin)->postJson('/api/staff', newMember($this, ['employee_code' => 'EMP-777']))->assertUnprocessable()->assertJsonValidationErrors('employee_code');

    // Another clinic may reuse it.
    ['tenant' => $other, 'branch' => $otherBranch] = clinic();
    $otherAdmin = staff($other, Role::Admin);
    actingAsStaff($otherAdmin)->postJson('/api/staff', newMember((object) ['branch' => $otherBranch], ['employee_code' => 'EMP-777']))->assertCreated();
});

it('lets managers reset a colleague\'s password and signs them out everywhere', function () {
    $token = inTenant($this->tenant, fn () => $this->therapist->createToken('Pixel 8')->plainTextToken);

    $res = actingAsStaff($this->admin)->postJson("/api/staff/{$this->therapist->id}/password", ['mode' => 'generate'])->assertOk();
    $temp = $res->json('meta.temporary_password');
    $fresh = $this->therapist->fresh();
    expect(Hash::check($temp, $fresh->password))->toBeTrue()->and($fresh->must_change_password)->toBeTrue()
        ->and($fresh->tokens()->count())->toBe(0);

    freshBrowser()->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();

    actingAsStaff($this->admin)->postJson("/api/staff/{$this->therapist->id}/password", ['mode' => 'set', 'password' => 'Chosen2026', 'password_confirmation' => 'Chosen2026', 'require_change' => false])
        ->assertOk()->assertJsonPath('meta.temporary_password', null);
    expect(Hash::check('Chosen2026', $this->therapist->fresh()->password))->toBeTrue()->and($this->therapist->fresh()->must_change_password)->toBeFalse();
});

it('guards password resets: managers only, never self, owners only by owners', function () {
    actingAsStaff($this->desk)->postJson("/api/staff/{$this->therapist->id}/password", ['mode' => 'generate'])->assertForbidden();
    actingAsStaff($this->admin)->postJson("/api/staff/{$this->admin->id}/password", ['mode' => 'generate'])->assertUnprocessable()->assertJsonPath('code', 'self_reset');
    actingAsStaff($this->admin)->postJson("/api/staff/{$this->owner->id}/password", ['mode' => 'generate'])->assertUnprocessable()->assertJsonPath('code', 'owner_only');
    actingAsStaff($this->owner)->postJson("/api/staff/{$this->admin->id}/password", ['mode' => 'generate'])->assertOk();

    // Cross-tenant ids are invisible.
    ['tenant' => $other] = clinic();
    $stranger = staff($other, Role::Therapist);
    actingAsStaff($this->owner)->postJson("/api/staff/{$stranger->id}/password", ['mode' => 'generate'])->assertNotFound();
});

it('shows an employee profile with stats to managers and to the person, hiding HR details from others', function () {
    inTenant($this->tenant, fn () => $this->therapist->forceFill(['hr_notes' => 'Probation ends March', 'address' => 'Kochi'])->save());

    actingAsStaff($this->admin)->getJson("/api/staff/{$this->therapist->id}")->assertOk()
        ->assertJsonPath('data.profile.hr_notes', 'Probation ends March')
        ->assertJsonPath('data.profile.role', 'therapist')
        ->assertJsonStructure(['data' => ['stats' => ['appointments' => ['total', 'completed', 'completion_rate'], 'attendance', 'leave_balances', 'presence_today'], 'permissions', 'devices', 'activity']]);

    actingAsStaff($this->therapist)->getJson("/api/staff/{$this->therapist->id}")->assertOk()
        ->assertJsonPath('data.profile.address', 'Kochi')
        ->assertJsonMissingPath('data.profile.hr_notes');

    actingAsStaff($this->desk)->getJson("/api/staff/{$this->therapist->id}")->assertForbidden();
});

it('summarises the team and lists presence for managers', function () {
    actingAsStaff($this->therapist)->postJson('/api/attendance/check-in')->assertOk();

    actingAsStaff($this->admin)->getJson('/api/staff/summary')->assertOk()
        ->assertJsonPath('data.total', 4)->assertJsonPath('data.clocked_in', 1);
    actingAsStaff($this->desk)->getJson('/api/staff/summary')->assertForbidden();

    $list = actingAsStaff($this->admin)->getJson('/api/staff?with_presence=1')->assertOk();
    expect($list->json("meta.presence.{$this->therapist->id}.state"))->toBe('in');

    // Non-managers get the slim picker list without HR fields or presence.
    $slim = actingAsStaff($this->desk)->getJson('/api/staff?with_presence=1')->assertOk();
    expect($slim->json('meta'))->toBeNull()->and($slim->json('data.0'))->not->toHaveKey('employee_code');
});

it('describes roles and their permissions', function () {
    $roles = actingAsStaff($this->admin)->getJson('/api/staff/roles')->assertOk()->json('data.roles');
    $therapist = collect($roles)->firstWhere('key', 'therapist');
    expect($therapist['label'])->toBe('Physiotherapist')->and($therapist['permissions'])->toContain('clinical.write')->and($therapist['members'])->toBe(1);
});

it('signs a deactivated member out of every device', function () {
    $token = inTenant($this->tenant, fn () => $this->therapist->createToken('iPhone')->plainTextToken);
    actingAsStaff($this->admin)->putJson("/api/staff/{$this->therapist->id}", ['is_active' => false])->assertOk();
    freshBrowser()->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
});

// ---- Mobile token auth ------------------------------------------------------

it('issues mobile tokens that work across the staff API and can be revoked', function () {
    $res = freshBrowser()->postJson('/api/auth/token', ['email' => $this->therapist->email, 'password' => 'Secret123', 'device_name' => 'Pixel 8'])->assertOk()
        ->assertJsonPath('data.user.id', $this->therapist->id)
        ->assertJsonPath('data.tenant.slug', $this->tenant->slug);
    $token = $res->json('token');

    freshBrowser()->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.user.email', $this->therapist->email);
    freshBrowser()->withToken($token)->getJson('/api/attendance/today')->assertOk();
    freshBrowser()->withToken($token)->getJson('/api/billing/uninvoiced-appointments')->assertForbidden(); // role still applies

    freshBrowser()->withToken($token)->postJson('/api/auth/logout')->assertOk();
    freshBrowser()->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
});

it('rejects bad credentials and inactive accounts for mobile tokens', function () {
    freshBrowser()->postJson('/api/auth/token', ['email' => $this->therapist->email, 'password' => 'wrong', 'device_name' => 'x'])->assertUnprocessable();
    inTenant($this->tenant, fn () => $this->therapist->forceFill(['is_active' => false])->save());
    freshBrowser()->postJson('/api/auth/token', ['email' => $this->therapist->email, 'password' => 'Secret123', 'device_name' => 'x'])->assertUnprocessable();
});

it('lets a mobile user change their password and keeps only the current device signed in', function () {
    $a = freshBrowser()->postJson('/api/auth/token', ['email' => $this->desk->email, 'password' => 'Secret123', 'device_name' => 'Phone'])->json('token');
    $b = freshBrowser()->postJson('/api/auth/token', ['email' => $this->desk->email, 'password' => 'Secret123', 'device_name' => 'Tablet'])->json('token');

    freshBrowser()->withToken($a)->putJson('/api/auth/password', ['current_password' => 'Secret123', 'password' => 'Better2026', 'password_confirmation' => 'Better2026'])->assertOk();
    freshBrowser()->withToken($a)->getJson('/api/auth/me')->assertOk();
    freshBrowser()->withToken($b)->getJson('/api/auth/me')->assertUnauthorized();
});
