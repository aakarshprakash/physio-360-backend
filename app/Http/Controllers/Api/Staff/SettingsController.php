<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Http\Resources\ConsentTemplateResource;
use App\Http\Resources\InsurancePayerResource;
use App\Http\Resources\PackagePlanResource;
use App\Http\Resources\RoomResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\TenantResource;
use App\Models\Branch;
use App\Models\ConsentTemplate;
use App\Models\InsurancePayer;
use App\Models\PackagePlan;
use App\Models\Room;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Clinic configuration. Reads are open to all staff (forms need the catalog);
 * writes need "settings.manage" (rooms need "staff.manage").
 *
 * @tags Staff · Settings
 */
class SettingsController extends Controller
{
    // ---- Clinic profile ---------------------------------------------------

    public function clinic(Request $request): TenantResource
    {
        return new TenantResource(Tenant::query()->with('currency')->findOrFail($request->user()->tenant_id));
    }

    public function updateClinic(Request $request): TenantResource
    {
        Gate::authorize('settings.manage');
        $tenant = Tenant::query()->findOrFail($request->user()->tenant_id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:500'],
            'timezone' => ['sometimes', 'timezone:all'],
            'locale' => ['sometimes', 'in:en,ar,ml'],
            'settings' => ['sometimes', 'array'],
            'settings.portal_booking_enabled' => ['sometimes', 'boolean'],
            'settings.portal_min_lead_minutes' => ['sometimes', 'integer', 'between:0,10080'],
            'settings.portal_cancellation_hours' => ['sometimes', 'integer', 'between:0,168'],
            'settings.reminder_hours_before' => ['sometimes', 'integer', 'between:1,72'],
            'settings.weekly_off_days' => ['sometimes', 'array', 'max:6'],
            'settings.weekly_off_days.*' => ['integer', 'between:0,6', 'distinct'],
            'settings.holidays' => ['sometimes', 'array', 'max:60'],
            'settings.holidays.*.date' => ['required', 'date_format:Y-m-d'],
            'settings.holidays.*.name' => ['required', 'string', 'max:96'],
            'settings.attendance_grace_minutes' => ['sometimes', 'integer', 'between:0,120'],
            'settings.payment_reminders' => ['sometimes', 'array'],
            'settings.payment_reminders.enabled' => ['sometimes', 'boolean'],
            'settings.payment_reminders.channel' => ['sometimes', 'in:email,sms,whatsapp'],
            'settings.payment_reminders.days_after_due' => ['sometimes', 'array', 'max:6'],
            'settings.payment_reminders.days_after_due.*' => ['integer', 'between:0,180', 'distinct'],
            'settings.payment_reminders.include_payment_link' => ['sometimes', 'boolean'],
            'razorpay' => ['sometimes', 'nullable', 'array'],
            'razorpay.key_id' => ['required_with:razorpay', 'string', 'max:64'],
            'razorpay.key_secret' => ['required_with:razorpay', 'string', 'max:128'],
            'razorpay.webhook_secret' => ['required_with:razorpay', 'string', 'max:128'],
            'twilio' => ['sometimes', 'nullable', 'array'],
            'twilio.account_sid' => ['required_with:twilio', 'string', 'regex:/^AC[0-9a-fA-F]{32}$/'],
            'twilio.auth_token' => ['required_with:twilio', 'string', 'max:128'],
            'twilio.sms_from' => ['nullable', 'string', 'max:32', 'regex:/^(\+[0-9]{8,15}|[A-Za-z0-9]{3,11}|MG[0-9a-fA-F]{32})$/'],
            'twilio.whatsapp_from' => ['nullable', 'string', 'max:32', 'regex:/^\+[0-9]{8,15}$/'],
        ]);

        if (array_key_exists('settings', $data)) {
            $data['settings'] = array_merge($tenant->settings ?? [], $data['settings']);
        }

        // Gateway secrets are write-only: stored encrypted, never returned. null disconnects.
        foreach (['razorpay', 'twilio'] as $gateway) {
            if ($request->has($gateway)) {
                $credentials = $tenant->gateway_credentials ?? [];
                if ($data[$gateway]) {
                    $credentials[$gateway] = $data[$gateway];
                } else {
                    unset($credentials[$gateway]);
                }
                $tenant->gateway_credentials = $credentials ?: null;
            }
            unset($data[$gateway]);
        }

        $tenant->fill($data)->save();

        return new TenantResource($tenant->load('currency'));
    }

    // ---- Branches ---------------------------------------------------------

    public function branches(): AnonymousResourceCollection
    {
        return BranchResource::collection(Branch::query()->orderBy('name')->get());
    }

    public function storeBranch(Request $request): JsonResponse
    {
        Gate::authorize('settings.manage');
        $branch = Branch::query()->create($this->branchData($request));

        // The creator should be able to work in the branch they just created.
        $request->user()->branches()->syncWithoutDetaching([$branch->id => ['tenant_id' => $branch->tenant_id]]);

        return (new BranchResource($branch))->response()->setStatusCode(201);
    }

    public function updateBranch(Request $request, Branch $branch): BranchResource
    {
        Gate::authorize('settings.manage');
        $branch->update($this->branchData($request, $branch));

        return new BranchResource($branch);
    }

    private function branchData(Request $request, ?Branch $branch = null): array
    {
        $required = $branch ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'code' => [$required, 'alpha_num:ascii', 'max:12', Rule::unique('branches', 'code')->where('tenant_id', $request->user()->tenant_id)->ignore($branch?->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:96'],
            'timezone' => ['nullable', 'timezone:all'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        return $data;
    }

    // ---- Rooms ------------------------------------------------------------

    public function rooms(Request $request): AnonymousResourceCollection
    {
        return RoomResource::collection(
            Room::query()->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))->orderBy('name')->get()
        );
    }

    public function storeRoom(Request $request): JsonResponse
    {
        Gate::authorize('staff.manage');

        return (new RoomResource(Room::query()->create($this->roomData($request))))->response()->setStatusCode(201);
    }

    public function updateRoom(Request $request, Room $room): RoomResource
    {
        Gate::authorize('staff.manage');
        $room->update($this->roomData($request, $room));

        return new RoomResource($room);
    }

    private function roomData(Request $request, ?Room $room = null): array
    {
        $required = $room ? 'sometimes' : 'required';

        return $request->validate([
            'branch_id' => [$required, 'integer', Rule::exists('branches', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'name' => [$required, 'string', 'max:96'],
            'type' => ['sometimes', 'in:treatment,gym,hydro,consult'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    // ---- Services & packages ---------------------------------------------

    public function services(Request $request): AnonymousResourceCollection
    {
        return ServiceResource::collection(
            Service::query()->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))->orderBy('name')->get()
        );
    }

    public function storeService(Request $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        return (new ServiceResource(Service::query()->create($this->serviceData($request))))->response()->setStatusCode(201);
    }

    public function updateService(Request $request, Service $service): ServiceResource
    {
        Gate::authorize('settings.manage');
        $service->update($this->serviceData($request, $service));

        return new ServiceResource($service);
    }

    private function serviceData(Request $request, ?Service $service = null): array
    {
        $required = $service ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', 'string', 'max:64'],
            'duration_minutes' => [$required, 'integer', 'between:10,480'],
            'price_minor' => [$required, 'integer', 'min:0'],
            'tax_rate_bp' => ['sometimes', 'integer', 'between:0,10000'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    public function packagePlans(Request $request): AnonymousResourceCollection
    {
        return PackagePlanResource::collection(
            PackagePlan::query()->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))->orderBy('name')->get()
        );
    }

    public function storePackagePlan(Request $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        return (new PackagePlanResource(PackagePlan::query()->create($this->packageData($request))))->response()->setStatusCode(201);
    }

    public function updatePackagePlan(Request $request, PackagePlan $plan): PackagePlanResource
    {
        Gate::authorize('settings.manage');
        $plan->update($this->packageData($request, $plan));

        return new PackagePlanResource($plan);
    }

    private function packageData(Request $request, ?PackagePlan $plan = null): array
    {
        $required = $plan ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'sessions_count' => [$required, 'integer', 'between:1,200'],
            'price_minor' => [$required, 'integer', 'min:0'],
            'validity_days' => [$required, 'integer', 'between:1,730'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    // ---- Insurance payers -------------------------------------------------

    public function payers(): AnonymousResourceCollection
    {
        return InsurancePayerResource::collection(InsurancePayer::query()->orderBy('name')->get());
    }

    public function storePayer(Request $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        return (new InsurancePayerResource(InsurancePayer::query()->create($this->payerData($request))))->response()->setStatusCode(201);
    }

    public function updatePayer(Request $request, InsurancePayer $payer): InsurancePayerResource
    {
        Gate::authorize('settings.manage');
        $payer->update($this->payerData($request, $payer));

        return new InsurancePayerResource($payer);
    }

    private function payerData(Request $request, ?InsurancePayer $payer = null): array
    {
        return $request->validate([
            'name' => [$payer ? 'sometimes' : 'required', 'string', 'max:120'],
            'type' => ['sometimes', 'in:insurer,tpa,corporate'],
            'code' => ['nullable', 'string', 'max:32'],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    // ---- Consent templates ------------------------------------------------

    public function consentTemplates(): AnonymousResourceCollection
    {
        return ConsentTemplateResource::collection(ConsentTemplate::query()->orderByDesc('is_active')->orderBy('title')->get());
    }

    public function storeConsentTemplate(Request $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        return (new ConsentTemplateResource(ConsentTemplate::query()->create($this->consentData($request))))->response()->setStatusCode(201);
    }

    /** Changing the text bumps the version so patients are asked to re-sign. */
    public function updateConsentTemplate(Request $request, ConsentTemplate $template): ConsentTemplateResource
    {
        Gate::authorize('settings.manage');
        $data = $this->consentData($request, $template);

        if (isset($data['body']) && $data['body'] !== $template->body) {
            $data['version'] = $template->version + 1;
        }

        $template->forceFill($data)->save();

        return new ConsentTemplateResource($template);
    }

    private function consentData(Request $request, ?ConsentTemplate $template = null): array
    {
        $required = $template ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:190'],
            'body' => [$required, 'string', 'max:20000'],
            'locale' => ['sometimes', 'in:en,ar,hi,ml'],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
