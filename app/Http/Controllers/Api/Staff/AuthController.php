<?php

namespace App\Http\Controllers\Api\Staff;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * @tags Staff · Auth
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $tenants) {}

    /**
     * Sign in (session cookie). Call GET /sanctum/csrf-cookie first.
     *
     * @unauthenticated
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $guard = Auth::guard('web');

        if (! $guard->attempt(['email' => strtolower($credentials['email']), 'password' => $credentials['password']], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        /** @var User $user */
        $user = $guard->user();
        $tenant = Tenant::query()->find($user->tenant_id);

        if (! $user->is_active || ! $tenant?->isActive()) {
            $guard->logout();
            throw ValidationException::withMessages(['email' => 'This account is not active. Please contact your clinic administrator.']);
        }

        $request->session()->regenerate();
        $request->session()->put('authenticated_at', time());
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $this->tenants->run($tenant, function () use ($user) {
            $this->audit->record('login', $user);

            return response()->json(['data' => $this->mePayload($user)]);
        });
    }

    /**
     * Mobile app sign-in: returns a bearer token (send as "Authorization: Bearer …").
     *
     * @unauthenticated
     */
    public function token(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:96'],
        ]);

        /** @var User|null $user */
        $user = User::query()->withoutGlobalScope(TenantScope::class)->where('email', strtolower($credentials['email']))->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $tenant = Tenant::query()->find($user->tenant_id);
        if (! $user->is_active || ! $tenant?->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account is not active. Please contact your clinic administrator.']);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $this->tenants->run($tenant, function () use ($user, $credentials) {
            $token = $user->createToken(Str::limit($credentials['device_name'], 90, ''));
            $this->audit->record('login', $user, [], ['channel' => 'mobile', 'device' => $credentials['device_name']]);

            return response()->json(['token' => $token->plainTextToken, 'data' => $this->mePayload($user)]);
        });
    }

    /** Sign out: revokes the mobile token, or ends the web session. */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return response()->json(['message' => 'Signed out.']);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    /** Current user, clinic, accessible branches and effective permissions. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->mePayload($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'locale' => ['sometimes', 'in:en,ar,ml'],
        ]);

        $request->user()->update($data);

        return response()->json(['data' => $this->mePayload($request->user()->fresh())]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::defaults()],
        ]);

        $user = $request->user();
        $current = $user->currentAccessToken();
        $user->setNewPassword($request->input('password'));

        // Sign out every other session and device.
        $user->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()))->delete();
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logoutOtherDevices($request->input('password'));
        }

        return response()->json(['message' => 'Password updated.']);
    }

    /** @unauthenticated */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::broker('users')->sendResetLink(['email' => strtolower($request->input('email'))]);

        // Same response whether or not the account exists (no enumeration).
        return response()->json(['message' => 'If that address belongs to an account, a reset link has been sent.']);
    }

    /** @unauthenticated */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker('users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->setNewPassword($password);
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => 'Password has been reset. You can sign in now.']);
    }

    private function mePayload(User $user): array
    {
        $user->loadMissing(['roles', 'branches']);
        $tenant = Tenant::query()->with('currency')->findOrFail($user->tenant_id);
        $branchIds = $user->accessibleBranchIds();

        return [
            'user' => new UserResource($user),
            'tenant' => new TenantResource($tenant),
            'branches' => BranchResource::collection(
                Branch::query()->where('is_active', true)->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))->orderBy('name')->get()
            ),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'must_change_password' => (bool) $user->must_change_password,
        ];
    }
}
