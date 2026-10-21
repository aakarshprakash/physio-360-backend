<?php

namespace App\Http\Controllers\Api\Staff;

use App\Audit\AuditLogger;
use App\Auth\PermissionMatrix;
use App\Auth\SessionRevoker;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffProfileResource;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Hr\StaffInsights;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Team directory, employee records, role-based access and credentials.
 *
 * @tags Staff · Team
 */
class StaffController extends Controller
{
    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract', 'visiting', 'intern'];

    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $tenants) {}

    /**
     * Everyone can list colleagues (needed for pickers); details are limited.
     * Team managers can ask for today's presence alongside each person.
     */
    public function index(Request $request, StaffInsights $insights): AnonymousResourceCollection
    {
        $request->validate([
            'practitioners_only' => ['nullable', 'boolean'],
            'include_inactive' => ['nullable', 'boolean'],
            'with_presence' => ['nullable', 'boolean'],
        ]);

        $users = User::query()
            ->when($request->boolean('practitioners_only'), fn ($q) => $q->where('is_practitioner', true))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->with(['roles', 'branches:id'])
            ->orderBy('name')
            ->get();

        $manager = $request->user()->can('staff.manage');
        $presence = $manager && $request->boolean('with_presence') ? $insights->presenceToday($users->pluck('id')->all()) : null;

        return ($manager ? StaffProfileResource::collection($users) : UserResource::collection($users))
            ->additional($presence !== null ? ['meta' => ['presence' => (object) $presence]] : []);
    }

    /** Headline numbers for the team directory. */
    public function summary(StaffInsights $insights): JsonResponse
    {
        Gate::authorize('staff.manage');

        return response()->json(['data' => $insights->teamSummary(User::query()->where('is_active', true)->get(['id', 'is_practitioner']))]);
    }

    /** Employee profile with this month's performance, attendance and leave. */
    public function show(Request $request, User $user, StaffInsights $insights): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->can('staff.manage') || $viewer->id === $user->id, 403);

        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $tz = $this->tenants->require()->timezone;
        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month'] ?? CarbonImmutable::now($tz)->format('Y-m'), $tz);

        $user->load(['roles', 'branches:id']);

        return response()->json(['data' => [
            'profile' => new StaffProfileResource($user),
            'stats' => $insights->profileStats($user, $month),
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values(),
            'devices' => $user->tokens()->orderByDesc('last_used_at')->get(['id', 'name', 'last_used_at', 'created_at'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'last_used_at' => $t->last_used_at?->utc()->toIso8601ZuluString(), 'created_at' => $t->created_at?->utc()->toIso8601ZuluString()]),
            'activity' => $viewer->can('staff.manage') ? AuditLog::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('actor_type', 'user')->where('actor_id', $user->id)
                ->latest('id')->limit(15)
                ->get(['id', 'event', 'auditable_type', 'auditable_id', 'ip_address', 'created_at'])
                ->map(fn (AuditLog $l) => ['id' => $l->id, 'event' => $l->event, 'subject' => $l->auditable_type, 'subject_id' => $l->auditable_id, 'ip' => $l->ip_address, 'at' => $l->created_at?->utc()->toIso8601ZuluString()])
                : [],
        ]]);
    }

    /** Roles, what each one can do, and how many people hold it. */
    public function roles(): JsonResponse
    {
        $counts = User::query()->where('is_active', true)->with('roles:id,name')->get(['id'])
            ->flatMap(fn (User $u) => $u->roles->pluck('name'))->countBy();

        return response()->json(['data' => [
            'permissions' => collect(PermissionMatrix::PERMISSIONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'roles' => collect(PermissionMatrix::grants())->map(fn ($perms, $role) => [
                'key' => $role,
                'label' => Role::from($role)->label(),
                'description' => Role::from($role)->description(),
                'permissions' => array_values($perms),
                'members' => $counts[$role] ?? 0,
            ])->values(),
        ]]);
    }

    /**
     * Add a team member and choose how they get their first password:
     * "invite" emails a set-password link, "password" uses the one given,
     * "generate" creates one and returns it once. Admin-issued passwords
     * must be changed at first sign-in.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('staff.manage');
        $data = $this->validated($request);
        $credentials = $request->validate([
            'credentials' => ['nullable', Rule::in(['invite', 'password', 'generate'])],
            'password' => ['required_if:credentials,password', 'nullable', 'string', 'confirmed', PasswordRule::defaults()],
        ]);
        $mode = $credentials['credentials'] ?? 'generate';
        $this->guardOwnerRole($request->user(), $data['role']);

        $password = $mode === 'password' ? $credentials['password'] : self::temporaryPassword();

        $user = DB::transaction(function () use ($data, $password, $mode) {
            $user = new User(collect($data)->except(['role', 'branch_ids', 'email'])->all());
            $user->email = strtolower($data['email']);
            $user->password = $password;
            $user->forceFill(['must_change_password' => $mode !== 'invite', 'password_changed_at' => now()]);
            $user->save();
            $user->branches()->sync(collect($data['branch_ids'])->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $user->tenant_id]])->all());
            $user->assignRole($data['role']);

            return $user;
        });

        if ($mode === 'invite') {
            Password::broker('users')->sendResetLink(['email' => $user->email]);
        }

        // A generated password is shown once; with no outgoing mail an invite falls back to it too.
        $reveal = $mode === 'generate' || ($mode === 'invite' && config('mail.default') === 'log');

        return (new StaffProfileResource($user->load(['roles', 'branches:id'])))
            ->additional(['meta' => ['temporary_password' => $reveal ? $password : null, 'credentials' => $mode]])
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): StaffProfileResource
    {
        Gate::authorize('staff.manage');
        $data = $this->validated($request, $user);

        if (isset($data['role'])) {
            $this->guardOwnerRole($request->user(), $data['role'], $user);
        }
        if (($data['is_active'] ?? true) === false) {
            if ($user->id === $request->user()->id) {
                throw new DomainRuleViolation('You cannot deactivate your own account.', 'self_deactivation');
            }
            $this->ensureAnotherOwner($user);
        }

        DB::transaction(function () use ($user, $data) {
            $user->update(collect($data)->except(['role', 'branch_ids'])->all());

            if (isset($data['branch_ids'])) {
                $user->branches()->sync(collect($data['branch_ids'])->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $user->tenant_id]])->all());
            }
            if (isset($data['role'])) {
                $user->syncRoles([$data['role']]);
            }
        });

        if (($data['is_active'] ?? true) === false) {
            $this->signOutEverywhere($user);
        }

        return new StaffProfileResource($user->load(['roles', 'branches:id']));
    }

    /**
     * Reset a team member's password: set one, generate one (returned once),
     * or email a reset link. Signs them out of every device.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        Gate::authorize('staff.manage');
        $data = $request->validate([
            'mode' => ['required', Rule::in(['set', 'generate', 'email'])],
            'password' => ['required_if:mode,set', 'nullable', 'string', 'confirmed', PasswordRule::defaults()],
            'require_change' => ['sometimes', 'boolean'],
        ]);

        if ($user->id === $request->user()->id) {
            throw new DomainRuleViolation('Change your own password from your profile.', 'self_reset');
        }
        if ($user->hasRole(Role::Owner->value) && ! $request->user()->hasRole(Role::Owner->value)) {
            throw new DomainRuleViolation('Only a clinic owner can reset an owner\'s password.', 'owner_only');
        }

        if ($data['mode'] === 'email') {
            Password::broker('users')->sendResetLink(['email' => $user->email]);
            $this->audit->record('password_reset_link_sent', $user);

            return response()->json(['message' => 'A password reset link has been emailed to '.$user->email.'.', 'meta' => ['temporary_password' => null]]);
        }

        $password = $data['mode'] === 'set' ? $data['password'] : self::temporaryPassword();
        $user->setNewPassword($password, $request->boolean('require_change', true));
        $this->signOutEverywhere($user);
        $this->audit->record('password_reset_by_admin', $user, [], ['mode' => $data['mode']]);

        return response()->json([
            'message' => 'Password updated. '.$user->name.' has been signed out of all devices.',
            'meta' => ['temporary_password' => $data['mode'] === 'generate' ? $password : null],
        ]);
    }

    /** Sign a team member out of the web portal and the mobile app. */
    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        Gate::authorize('staff.manage');
        if ($user->hasRole(Role::Owner->value) && ! $request->user()->hasRole(Role::Owner->value)) {
            throw new DomainRuleViolation('Only a clinic owner can sign out an owner.', 'owner_only');
        }

        $this->signOutEverywhere($user);
        $this->audit->record('sessions_revoked', $user);

        return response()->json(['message' => $user->name.' has been signed out of all devices.']);
    }

    public function workingHours(User $user): JsonResponse
    {
        return response()->json(['data' => WorkingHour::query()->where('user_id', $user->id)->orderBy('branch_id')->orderBy('weekday')->orderBy('start_time')->get()
            ->map(fn ($wh) => [
                'branch_id' => $wh->branch_id,
                'weekday' => $wh->weekday,
                'start_time' => substr($wh->start_time, 0, 5),
                'end_time' => substr($wh->end_time, 0, 5),
            ])]);
    }

    /** Replace the weekly schedule. Intervals on the same branch/day must not overlap. */
    public function updateWorkingHours(Request $request, User $user): JsonResponse
    {
        Gate::authorize('staff.manage');

        $data = $request->validate([
            'hours' => ['present', 'array', 'max:60'],
            'hours.*.branch_id' => ['required', 'integer', Rule::exists('branch_user', 'branch_id')->where('user_id', $user->id)],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6'],
            'hours.*.start_time' => ['required', 'date_format:H:i'],
            'hours.*.end_time' => ['required', 'date_format:H:i'],
        ]);

        $groups = collect($data['hours'])->groupBy(fn ($h) => $h['branch_id'].'-'.$h['weekday']);
        foreach ($groups as $rows) {
            $sorted = $rows->sortBy('start_time')->values();
            foreach ($sorted as $i => $row) {
                if ($row['end_time'] <= $row['start_time'] || ($i > 0 && $row['start_time'] < $sorted[$i - 1]['end_time'])) {
                    throw new DomainRuleViolation('Working hours must end after they start and must not overlap.', 'invalid_hours');
                }
            }
        }

        DB::transaction(function () use ($user, $data) {
            WorkingHour::query()->where('user_id', $user->id)->delete();
            foreach ($data['hours'] as $row) {
                WorkingHour::query()->create($row + ['user_id' => $user->id]);
            }
        });

        return $this->workingHours($user);
    }

    /** Readable but strong: an upper-case letter, lower-case letters and digits. */
    public static function temporaryPassword(): string
    {
        $letters = 'abcdefghjkmnpqrstuvwxyz';
        $word = '';
        for ($i = 0; $i < 6; $i++) {
            $word .= $letters[random_int(0, strlen($letters) - 1)];
        }

        return ucfirst($word).'-'.random_int(1000, 9999);
    }

    private function signOutEverywhere(User $user): void
    {
        SessionRevoker::everywhere($user);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $required = $user ? 'sometimes' : 'required';
        $tenantId = $request->user()->tenant_id;

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'email' => [$user ? 'prohibited' : 'required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'title' => ['nullable', 'string', 'max:96'],
            'specialization' => ['nullable', 'string', 'max:128'],
            'color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_practitioner' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'role' => [$required, Rule::enum(Role::class)],
            'branch_ids' => [$required, 'array', 'min:1'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'employee_code' => ['nullable', 'string', 'max:32', Rule::unique('users', 'employee_code')->where('tenant_id', $tenantId)->ignore($user?->id)],
            'employment_type' => ['nullable', Rule::in(self::EMPLOYMENT_TYPES)],
            'joined_on' => ['nullable', 'date_format:Y-m-d'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'qualification' => ['nullable', 'string', 'max:190'],
            'registration_number' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'hr_notes' => ['nullable', 'string', 'max:2000'],
            'geofence_exempt' => ['sometimes', 'boolean'],
            'online_bookable' => ['sometimes', 'boolean'],
        ]);
    }

    private function guardOwnerRole(User $actor, string $role, ?User $target = null): void
    {
        $touchesOwner = $role === Role::Owner->value || $target?->hasRole(Role::Owner->value);

        if ($touchesOwner && ! $actor->hasRole(Role::Owner->value)) {
            throw new DomainRuleViolation('Only a clinic owner can grant or change the owner role.', 'owner_only');
        }

        if ($target && $target->hasRole(Role::Owner->value) && $role !== Role::Owner->value) {
            $this->ensureAnotherOwner($target);
        }
    }

    private function ensureAnotherOwner(User $leaving): void
    {
        $owners = User::role(Role::Owner->value)->where('is_active', true)->whereKeyNot($leaving->id)->count();

        if ($leaving->hasRole(Role::Owner->value) && $owners === 0) {
            throw new DomainRuleViolation('A clinic must keep at least one active owner.', 'last_owner');
        }
    }
}
