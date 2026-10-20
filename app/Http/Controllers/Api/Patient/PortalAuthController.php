<?php

namespace App\Http\Controllers\Api\Patient;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Tenant;
use App\Support\BlindIndex;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Patient identities use their own guard and provider; staff sessions and
 * permissions never apply to them.
 *
 * @tags Patient app · Auth
 */
class PortalAuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $tenants) {}

    /** @unauthenticated */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clinic' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $tenant = $this->activeTenant($data['clinic']);
        $patient = $tenant ? $this->patientQuery($tenant)->where('email_index', BlindIndex::email($data['email']))->first() : null;

        // Constant-ish work whether or not the account exists.
        $valid = $patient && $patient->portal_enabled && $patient->portal_password
            ? Hash::check($data['password'], $patient->portal_password)
            : Hash::check($data['password'], self::dummyHash()) && false;

        if (! $valid) {
            throw ValidationException::withMessages(['email' => 'These details do not match an active patient account.']);
        }

        return $this->startSession($request, $tenant, $patient);
    }

    /**
     * Set a password using the one-time link from the clinic, then sign in.
     *
     * @unauthenticated
     */
    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clinic' => ['required', 'string', 'max:64'],
            'token' => ['required', 'string', 'size:48'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $tenant = $this->activeTenant($data['clinic']);
        $patient = $tenant ? $this->patientQuery($tenant)
            ->where('portal_invite_token_hash', hash('sha256', $data['token']))
            ->where('portal_invite_expires_at', '>', now())
            ->first() : null;

        if (! $patient) {
            throw ValidationException::withMessages(['token' => 'This activation link is invalid or has expired. Ask the clinic for a new one.']);
        }

        $patient->forceFill([
            'portal_password' => $data['password'],
            'portal_enabled' => true,
            'portal_invite_token_hash' => null,
            'portal_invite_expires_at' => null,
        ])->save();

        return $this->startSession($request, $tenant, $patient);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('patient')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    /** Public clinic branding for the login screen. @unauthenticated */
    public function clinic(string $slug): JsonResponse
    {
        $tenant = $this->activeTenant($slug);
        abort_unless($tenant, 404);

        return response()->json(['data' => ['name' => $tenant->name, 'slug' => $tenant->slug, 'locale' => $tenant->locale]]);
    }

    private function startSession(Request $request, Tenant $tenant, Patient $patient): JsonResponse
    {
        Auth::guard('patient')->login($patient);
        $request->session()->regenerate();
        $request->session()->put('authenticated_at', time());

        return $this->tenants->run($tenant, function () use ($patient) {
            $patient->forceFill(['portal_last_login_at' => now()])->saveQuietly();
            $this->audit->record('portal_login', $patient);

            return response()->json(['data' => PortalController::profile($patient)]);
        });
    }

    private static function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make(bin2hex(random_bytes(16)));
    }

    private function activeTenant(string $slug): ?Tenant
    {
        $tenant = Tenant::query()->where('slug', strtolower($slug))->first();

        return $tenant?->isActive() ? $tenant : null;
    }

    private function patientQuery(Tenant $tenant)
    {
        return Patient::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id);
    }
}
