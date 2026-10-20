<?php

namespace App\Http\Controllers\Api\Platform;

use App\Audit\AuditLogger;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Currency;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * SYSNAC console: tenant lifecycle and platform metrics. Operates across
 * tenants deliberately, with no tenant context and no access to clinical data.
 *
 * @tags SYSNAC console
 */
class PlatformController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @unauthenticated */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        if (! Auth::guard('platform')->attempt(['email' => strtolower($credentials['email']), 'password' => $credentials['password']])) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();
        $request->session()->put('authenticated_at', time());
        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform')->user();
        $admin->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('platform_login', $admin);

        return response()->json(['data' => $this->adminPayload($admin)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(): JsonResponse
    {
        return response()->json(['data' => $this->adminPayload(Auth::guard('platform')->user())]);
    }

    /** Aggregate platform figures (counts only, no PHI). */
    public function metrics(): JsonResponse
    {
        $since = now()->subDays(30);

        $tenants = Tenant::query()->selectRaw("COUNT(*) AS total, SUM(status = 'active') AS active, SUM(created_at >= ?) AS new_30d", [$since])->first();

        return response()->json(['data' => [
            'tenants_total' => (int) $tenants->total,
            'tenants_active' => (int) $tenants->active,
            'tenants_new_30d' => (int) $tenants->new_30d,
            'staff_total' => DB::table('users')->where('is_active', true)->count(),
            'patients_total' => DB::table('patients')->whereNull('deleted_at')->count(),
            'appointments_30d' => DB::table('appointments')->where('starts_at', '>=', $since)->count(),
            'by_plan' => Tenant::query()->groupBy('plan')->selectRaw('plan, COUNT(*) AS count')->pluck('count', 'plan'),
            'by_country' => Tenant::query()->groupBy('country_code')->selectRaw('country_code, COUNT(*) AS count')->pluck('count', 'country_code'),
        ]]);
    }

    public function tenants(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(TenantStatus::class)]]);

        $page = Tenant::query()
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->input('search').'%')
                ->orWhere('slug', 'like', '%'.$request->input('search').'%')
                ->orWhere('email', 'like', '%'.$request->input('search').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->addSelect([
                'tenants.*',
                'users_count' => DB::table('users')->selectRaw('COUNT(*)')->whereColumn('users.tenant_id', 'tenants.id'),
                'patients_count' => DB::table('patients')->selectRaw('COUNT(*)')->whereColumn('patients.tenant_id', 'tenants.id')->whereNull('deleted_at'),
                'branches_count' => DB::table('branches')->selectRaw('COUNT(*)')->whereColumn('branches.tenant_id', 'tenants.id'),
            ])
            ->latest('id')
            ->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Tenant $t) => $this->tenantPayload($t)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ]);
    }

    public function showTenant(Tenant $tenant): JsonResponse
    {
        $stats = [
            'users' => DB::table('users')->where('tenant_id', $tenant->id)->count(),
            'patients' => DB::table('patients')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->count(),
            'branches' => DB::table('branches')->where('tenant_id', $tenant->id)->count(),
            'appointments_30d' => DB::table('appointments')->where('tenant_id', $tenant->id)->where('starts_at', '>=', now()->subDays(30))->count(),
            'last_staff_login' => DB::table('users')->where('tenant_id', $tenant->id)->max('last_login_at'),
        ];

        $owner = DB::table('users')
            ->join('model_has_roles', fn ($j) => $j->on('model_has_roles.model_id', '=', 'users.id')->where('model_has_roles.model_type', User::class))
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('users.tenant_id', $tenant->id)->where('roles.name', 'owner')
            ->select('users.name', 'users.email')->first();

        return response()->json(['data' => $this->tenantPayload($tenant) + ['stats' => $stats, 'owner' => $owner]]);
    }

    /** Provision a clinic with its first branch, owner account and starter catalog. */
    public function createTenant(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'alpha_dash:ascii', 'min:3', 'max:48', 'unique:tenants,slug', Rule::notIn(['www', 'api', 'admin', 'console', 'my', 'app'])],
            'country_code' => ['required', Rule::in(['IN', 'AE', 'SA', 'KW', 'BH', 'OM', 'QA'])],
            'currency_code' => ['required', Rule::exists('currencies', 'code')],
            'timezone' => ['required', 'timezone:all'],
            'plan' => ['required', 'in:trial,standard,premium'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'branch_name' => ['required', 'string', 'max:120'],
            'branch_code' => ['required', 'alpha_num:ascii', 'max:12'],
            'city' => ['nullable', 'string', 'max:96'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'unique:users,email'],
            'owner_password' => ['required', Password::defaults()],
        ]);

        $tenant = $provisioner->provision(
            collect($data)->except(['owner_name', 'owner_email', 'owner_password'])->all(),
            ['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => $data['owner_password']],
        );

        $this->audit->record('tenant_provisioned', $tenant, [], ['name' => $tenant->name, 'slug' => $tenant->slug], $tenant->id);

        return response()->json(['data' => $this->tenantPayload($tenant->refresh())], 201);
    }

    public function updateTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::enum(TenantStatus::class)],
            'plan' => ['sometimes', 'in:trial,standard,premium'],
            'trial_ends_at' => ['nullable', 'date'],
        ]);

        $tenant->update($data);

        return response()->json(['data' => $this->tenantPayload($tenant)]);
    }

    public function currencies(): JsonResponse
    {
        return response()->json(['data' => Currency::query()->orderBy('code')->get()]);
    }

    public function audit(): AnonymousResourceCollection
    {
        return AuditLogResource::collection(
            AuditLog::query()->where(fn ($q) => $q->where('actor_type', 'platform')->orWhereIn('event', ['tenant_provisioned']))
                ->latest('id')->paginate(50)
        );
    }

    private function tenantPayload(Tenant $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
            'email' => $t->email,
            'phone' => $t->phone,
            'country_code' => $t->country_code,
            'currency_code' => $t->currency_code,
            'timezone' => $t->timezone,
            'status' => $t->status->value,
            'plan' => $t->plan,
            'trial_ends_at' => $t->trial_ends_at?->toDateString(),
            'users_count' => isset($t->users_count) ? (int) $t->users_count : null,
            'patients_count' => isset($t->patients_count) ? (int) $t->patients_count : null,
            'branches_count' => isset($t->branches_count) ? (int) $t->branches_count : null,
            'created_at' => $t->created_at?->toIso8601ZuluString(),
        ];
    }

    private function adminPayload(PlatformAdmin $admin): array
    {
        return ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email];
    }
}
