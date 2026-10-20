<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\WorkingHour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * @tags Staff · Team
 */
class StaffController extends Controller
{
    /** Everyone can list colleagues (needed for pickers); details are limited. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['practitioners_only' => ['nullable', 'boolean'], 'include_inactive' => ['nullable', 'boolean']]);

        return UserResource::collection(
            User::query()
                ->when($request->boolean('practitioners_only'), fn ($q) => $q->where('is_practitioner', true))
                ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
                ->with(['roles', 'branches:id'])
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * Add a team member. They receive a password-setup email (reset link);
     * a temporary password is returned only when no mail can be sent.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('staff.manage');
        $data = $this->validated($request);
        $this->guardOwnerRole($request->user(), $data['role']);

        $temporaryPassword = Str::password(14);

        $user = DB::transaction(function () use ($data, $temporaryPassword) {
            $user = new User(collect($data)->except(['role', 'branch_ids', 'email'])->all());
            $user->email = strtolower($data['email']);
            $user->password = $temporaryPassword;
            $user->save();
            $user->branches()->sync(collect($data['branch_ids'])->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $user->tenant_id]])->all());
            $user->assignRole($data['role']);

            return $user;
        });

        Password::broker('users')->sendResetLink(['email' => $user->email]);

        return (new UserResource($user->load(['roles', 'branches:id'])))
            ->additional(['meta' => ['temporary_password' => config('mail.default') === 'log' ? $temporaryPassword : null]])
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserResource
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

        return new UserResource($user->load(['roles', 'branches:id']));
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
