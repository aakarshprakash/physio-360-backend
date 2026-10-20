<?php

namespace App\Services;

use App\Auth\RoleRegistrar;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Branch;
use App\Models\ConsentTemplate;
use App\Models\DocumentSequence;
use App\Models\Exercise;
use App\Models\LeaveType;
use App\Models\MessageTemplate;
use App\Models\PackagePlan;
use App\Models\Room;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Sequences\SequenceAllocator;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a ready-to-use clinic: tenant, first branch, owner account, role
 * assignment, starter service catalog, exercise library, consent templates
 * and document sequences.
 */
class TenantProvisioner
{
    /** Rough local price level relative to the INR catalog, for starter prices only. */
    private const PRICE_FACTORS = [
        'INR' => '1', 'AED' => '0.3', 'SAR' => '0.3', 'QAR' => '0.3',
        'KWD' => '0.025', 'BHD' => '0.03', 'OMR' => '0.03', 'USD' => '0.08',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly RoleRegistrar $roles,
    ) {}

    /**
     * @param  array{name:string, slug?:string, country_code?:string, currency_code?:string, timezone?:string,
     *               email?:string, phone?:string, plan?:string, branch_name?:string, branch_code?:string, city?:string}  $clinic
     * @param  array{name:string, email:string, password:string, is_practitioner?:bool, title?:string}  $owner
     */
    public function provision(array $clinic, array $owner): Tenant
    {
        $this->roles->sync();

        return DB::transaction(function () use ($clinic, $owner) {
            $tenant = Tenant::query()->create([
                'name' => $clinic['name'],
                'slug' => $clinic['slug'] ?? $this->uniqueSlug($clinic['name']),
                'email' => $clinic['email'] ?? $owner['email'],
                'phone' => $clinic['phone'] ?? null,
                'country_code' => $clinic['country_code'] ?? 'IN',
                'currency_code' => $clinic['currency_code'] ?? 'INR',
                'timezone' => $clinic['timezone'] ?? 'Asia/Kolkata',
                'locale' => $clinic['locale'] ?? 'en',
                'status' => TenantStatus::Active,
                'plan' => $clinic['plan'] ?? 'standard',
                'trial_ends_at' => now()->addDays(14),
                'settings' => [
                    'portal_booking_enabled' => true,
                    'portal_min_lead_minutes' => 120,
                    'portal_cancellation_hours' => 12,
                    'reminder_hours_before' => 24,
                ],
            ]);

            return $this->context->run($tenant, function (Tenant $tenant) use ($clinic, $owner) {
                $branch = Branch::query()->create([
                    'name' => $clinic['branch_name'] ?? 'Main Clinic',
                    'code' => strtoupper($clinic['branch_code'] ?? 'MAIN'),
                    'city' => $clinic['city'] ?? null,
                    'phone' => $clinic['phone'] ?? null,
                ]);

                $user = new User([
                    'name' => $owner['name'],
                    'email' => strtolower($owner['email']),
                    'title' => $owner['title'] ?? 'Clinic Owner',
                    'is_practitioner' => $owner['is_practitioner'] ?? true,
                ]);
                $user->password = $owner['password'];
                $user->save();
                $user->branches()->attach($branch->id, ['tenant_id' => $tenant->id]);
                $user->assignRole(Role::Owner->value);

                if ($user->is_practitioner) {
                    $this->defaultWorkingHours($user, $branch);
                }

                Room::query()->create(['branch_id' => $branch->id, 'name' => 'Treatment Room 1']);
                Room::query()->create(['branch_id' => $branch->id, 'name' => 'Treatment Room 2']);
                Room::query()->create(['branch_id' => $branch->id, 'name' => 'Gym Area', 'type' => 'gym']);

                DocumentSequence::query()->create(['type' => 'patient_mrn', 'scope_key' => 'tenant', 'prefix' => 'P-', 'next_number' => 1001, 'padding' => 4]);
                $this->seedCatalog($tenant);
                $this->seedOperationalDefaults($tenant);

                return $tenant;
            });
        });
    }

    public function defaultWorkingHours(User $user, Branch $branch): void
    {
        // Monday-Saturday, 09:00-13:00 and 14:00-19:00 local time.
        foreach (range(1, 6) as $weekday) {
            foreach ([['09:00', '13:00'], ['14:00', '19:00']] as [$from, $to]) {
                WorkingHour::query()->create([
                    'user_id' => $user->id,
                    'branch_id' => $branch->id,
                    'weekday' => $weekday,
                    'start_time' => $from,
                    'end_time' => $to,
                ]);
            }
        }
    }

    /**
     * Leave types, message templates and the counters later modules use.
     * Idempotent, so it also backfills clinics created before those modules.
     * Must run inside the tenant's context.
     */
    public function seedOperationalDefaults(Tenant $tenant): void
    {
        DocumentSequence::query()->firstOrCreate(['type' => 'support_ticket', 'scope_key' => 'tenant'], ['prefix' => 'SUP-', 'padding' => 5, 'next_number' => 1]);
        foreach (Branch::query()->get() as $branch) {
            SequenceAllocator::ensureBranchSequences($branch);
        }

        $gulf = $tenant->country_code !== 'IN';
        $leaveTypes = $gulf
            ? [['Annual Leave', 'AL', 30, true, '#3E8E76'], ['Sick Leave', 'SL', 15, true, '#E07A5F'], ['Emergency Leave', 'EL', 5, true, '#8E7DBE'], ['Unpaid Leave', 'UL', 0, false, '#9AA5AB']]
            : [['Casual Leave', 'CL', 12, true, '#3E8E76'], ['Sick Leave', 'SL', 12, true, '#E07A5F'], ['Earned Leave', 'EL', 15, true, '#4C8DBE'], ['Loss of Pay', 'LOP', 0, false, '#9AA5AB']];
        foreach ($leaveTypes as [$name, $code, $quota, $paid, $color]) {
            LeaveType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'annual_quota' => $quota, 'is_paid' => $paid, 'color' => $color]);
        }

        if (MessageTemplate::query()->exists()) {
            return;
        }
        $templates = require database_path('data/message_templates.php');
        $locales = array_unique(['en', $gulf ? 'ar' : 'ml', $tenant->locale]);
        foreach ($templates as $row) {
            if (in_array($row['locale'], $locales, true)) {
                MessageTemplate::query()->create($row + ['is_default' => $row['locale'] === 'en']);
            }
        }
    }

    private function seedCatalog(Tenant $tenant): void
    {
        $catalog = require database_path('data/default_catalog.php');
        $currency = $tenant->currency_code;

        $services = [];
        foreach ($catalog['services'] as $row) {
            $services[$row['code']] = Service::query()->create([
                'name' => $row['name'],
                'code' => $row['code'],
                'category' => $row['category'],
                'duration_minutes' => $row['duration_minutes'],
                'price_minor' => $this->localPrice($row['price'], $currency),
                'color' => $row['color'],
            ]);
        }

        foreach ($catalog['package_plans'] as $row) {
            PackagePlan::query()->create([
                'name' => $row['name'],
                'service_id' => $services[$row['service_code']]->id,
                'sessions_count' => $row['sessions_count'],
                'price_minor' => $this->localPrice($row['price'], $currency),
                'validity_days' => $row['validity_days'],
            ]);
        }

        foreach ($catalog['consent_templates'] as $row) {
            ConsentTemplate::query()->create($row);
        }

        foreach ($catalog['exercises'] as [$name, $region, $category, $difficulty, $sets, $reps, $hold, $instructions]) {
            Exercise::query()->create([
                'name' => $name,
                'body_region' => $region,
                'category' => $category,
                'difficulty' => $difficulty,
                'default_sets' => $sets,
                'default_reps' => $reps,
                'default_hold_seconds' => $hold,
                'instructions' => $instructions,
                'description' => Str::limit($instructions, 80),
            ]);
        }
    }

    private function localPrice(string $inrMajor, string $currency): int
    {
        $factor = self::PRICE_FACTORS[$currency] ?? '1';
        $major = (string) max(1, (int) round((float) $inrMajor * (float) $factor));

        return Money::toMinor($major, $currency);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'clinic';
        $slug = $base;
        $i = 2;
        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
