<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PatientService;
use App\Services\Scheduling\BookingRequest;
use App\Services\TenantProvisioner;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Arch');
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/*
| Fixtures. Clinics are provisioned through the real TenantProvisioner so every
| test runs against the same starter data a new customer gets.
*/

/**
 * @return array{tenant: Tenant, owner: User, branch: Branch}
 */
function clinic(array $overrides = []): array
{
    static $n = 0;
    $n++;

    $tenant = app(TenantProvisioner::class)->provision(array_merge([
        'name' => "Test Clinic {$n}",
        'slug' => "clinic-{$n}-".strtolower(Str::random(4)),
        'currency_code' => 'INR',
        'timezone' => 'Asia/Kolkata',
        'branch_code' => 'KOC',
    ], $overrides), [
        'name' => "Owner {$n}",
        'email' => "owner{$n}-".Str::random(6).'@example.test',
        'password' => 'Secret123',
    ]);

    return app(TenantContext::class)->run($tenant, fn () => [
        'tenant' => $tenant,
        'owner' => User::query()->firstOrFail(),
        'branch' => Branch::query()->firstOrFail(),
    ]);
}

/** Run a callback inside a tenant context (like a request or job would). */
function inTenant(Tenant $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->run($tenant, $callback);
}

function staff(Tenant $tenant, Role $role, array $attributes = [], ?array $branches = null): User
{
    return inTenant($tenant, function () use ($role, $attributes, $branches) {
        $user = new User(array_merge([
            'name' => ucfirst($role->value).' '.Str::random(4),
            'email' => $role->value.'-'.Str::random(8).'@example.test',
            'is_practitioner' => $role === Role::Therapist,
        ], $attributes));
        $user->password = 'Secret123';
        $user->save();

        $branchIds = $branches ?? Branch::query()->pluck('id')->all();
        $user->branches()->sync(collect($branchIds)->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $user->tenant_id]])->all());
        $user->assignRole($role->value);

        if ($user->is_practitioner) {
            foreach (Branch::query()->whereIn('id', $branchIds)->get() as $branch) {
                app(TenantProvisioner::class)->defaultWorkingHours($user, $branch);
            }
        }

        return $user->fresh();
    });
}

function patient(Tenant $tenant, array $attributes = []): Patient
{
    return inTenant($tenant, fn () => app(PatientService::class)->create(array_merge([
        'first_name' => 'Test',
        'last_name' => 'Patient '.Str::random(4),
        'phone' => '+91 98765 '.random_int(10000, 99999),
        'email' => 'p'.Str::random(8).'@example.test',
        'gender' => 'female',
        'date_of_birth' => '1990-05-05',
        'primary_branch_id' => Branch::query()->value('id'),
    ], $attributes)));
}

function service(Tenant $tenant, string $code = 'FOLLOW'): Service
{
    return inTenant($tenant, fn () => Service::query()->where('code', $code)->firstOrFail());
}

/** Next local weekday (Mon-Sat) at the given time in the clinic timezone. */
function nextWorkday(string $time = '10:00', string $tz = 'Asia/Kolkata', int $daysAhead = 1): CarbonImmutable
{
    $day = CarbonImmutable::now($tz)->startOfDay()->addDays($daysAhead);
    while ($day->dayOfWeek === 0) {
        $day = $day->addDay();
    }

    return CarbonImmutable::parse($day->toDateString().' '.$time, $tz);
}

function bookingRequest(Patient $patient, User $practitioner, CarbonImmutable $localStart, array $overrides = []): BookingRequest
{
    return new BookingRequest(...array_merge([
        'patientId' => $patient->id,
        'practitionerId' => $practitioner->id,
        'branchId' => $patient->primary_branch_id,
        'localStart' => $localStart,
        'durationMinutes' => 45,
        'timezone' => $localStart->getTimezone()->getName(),
    ], $overrides));
}

/** Authenticate as a staff member through the Sanctum-protected stack. */
function actingAsStaff(User $user): TestCase
{
    return freshBrowser()->actingAs($user, 'web');
}

function actingAsPatient(Patient $patient): TestCase
{
    $patient->forceFill(['portal_enabled' => true])->saveQuietly();

    return freshBrowser()->actingAs($patient, 'patient');
}

/**
 * Simulate a new browser session: guards cache users across in-test requests,
 * and Sanctum's AuthenticateSession logs a session out when its identity changes.
 */
function freshBrowser(): TestCase
{
    app('auth')->forgetGuards();

    return test()->flushSession();
}

/** A small hand-drawn-looking PNG signature as a data URL. */
function signaturePng(): string
{
    $img = imagecreatetruecolor(240, 80);
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefill($img, 0, 0, $white);
    imageline($img, 10, 60, 230, 20, imagecolorallocate($img, 20, 45, 54));
    ob_start();
    imagepng($img);

    return 'data:image/png;base64,'.base64_encode(ob_get_clean());
}
