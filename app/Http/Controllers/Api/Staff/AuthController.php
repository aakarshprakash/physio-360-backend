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
use Illuminate\Support\Facades\Auth;
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

    /** Sign out and invalidate the session. */
    public function logout(Request $request): JsonResponse
    {
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
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = $request->user();
        $user->password = $request->input('password');
        $user->save();
        Auth::guard('web')->logoutOtherDevices($request->input('password'));

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
                $user->password = $password;
                $user->setRememberToken(null);
                $user->saveQuietly();
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
        ];
    }
}
