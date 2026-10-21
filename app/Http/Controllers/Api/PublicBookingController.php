<?php

namespace App\Http\Controllers\Api;

use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Scheduling\OnlineBookingService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The clinic's public booking page: shareable link, website embed and
 * WhatsApp. Read endpoints expose only what a visitor needs to pick a slot
 * (never other patients' bookings); the write endpoint is rate-limited and
 * honeypot-protected.
 *
 * @tags Public
 */
class PublicBookingController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly OnlineBookingService $online) {}

    /**
     * Clinic profile, branches, services and practitioners for the booking page.
     *
     * @unauthenticated
     */
    public function show(string $slug): JsonResponse
    {
        $tenant = $this->tenant($slug);

        return $this->context->run($tenant, function () use ($tenant) {
            $settings = $this->online->settings();
            $tenant->loadMissing('currency');

            return response()->json(['data' => [
                'clinic' => [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'phone' => $tenant->phone,
                    'email' => $tenant->email,
                    'address' => $tenant->address,
                    'logo_url' => $tenant->logo_path ? url('/api/public/clinics/'.$tenant->slug.'/logo').'?v='.substr(hash('xxh3', $tenant->logo_path), 0, 8) : null,
                    'timezone' => $tenant->timezone,
                    'currency' => ['code' => $tenant->currency->code, 'symbol' => $tenant->currency->symbol, 'exponent' => $tenant->currency->exponent],
                    'country_code' => $tenant->country_code,
                ],
                'settings' => [
                    'enabled' => $settings['enabled'],
                    'mode' => $settings['mode'],
                    'max_days_ahead' => $settings['max_days_ahead'],
                    'allow_practitioner_choice' => $settings['allow_practitioner_choice'],
                    'intro' => $settings['intro'],
                ],
                'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get()->map(fn (Branch $b) => [
                    'id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'address' => $b->address, 'city' => $b->city, 'phone' => $b->phone,
                ]),
                'services' => $settings['enabled'] ? $this->online->services()->map(fn (Service $s) => [
                    'id' => $s->id, 'name' => $s->name, 'category' => $s->category, 'description' => $s->description,
                    'duration_minutes' => $s->duration_minutes, 'price_minor' => $s->price_minor,
                ])->values() : [],
                'practitioners' => $settings['enabled'] && $settings['allow_practitioner_choice'] ? $this->online->practitioners()->map(fn ($u) => [
                    'id' => $u->id, 'name' => $u->name, 'title' => $u->title, 'specialization' => $u->specialization,
                    'color' => $u->color, 'branch_ids' => $u->branches->pluck('id')->values(),
                ])->values() : [],
            ]]);
        });
    }

    /**
     * Free start times on a date ("any available" when no practitioner is chosen).
     *
     * @unauthenticated
     */
    public function slots(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->tenant($slug);
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'practitioner_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->context->run($tenant, function () use ($data) {
            $branch = Branch::query()->where('is_active', true)->findOrFail($data['branch_id']);
            $service = $this->online->services()->firstWhere('id', $data['service_id']) ?? abort(404);
            $slots = $this->online->slots($branch, $service, CarbonImmutable::parse($data['date'], $branch->effectiveTimezone()), $data['practitioner_id'] ?? null);

            // Which practitioner is free is not the visitor's business beyond "someone is".
            return response()->json(['data' => array_map(fn ($s) => ['local_start' => $s['local_start'], 'starts_at' => $s['starts_at']], $slots)]);
        });
    }

    /**
     * Request (or, in instant mode, book) an appointment.
     *
     * @unauthenticated
     */
    public function store(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->tenant($slug);
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'practitioner_id' => ['nullable', 'integer'],
            'local_start' => ['required', 'date_format:Y-m-d\TH:i'],
            'name' => ['required', 'string', 'min:2', 'max:128'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^[+0-9 ()\-]{7,32}$/'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'age' => ['nullable', 'integer', 'between:1,120'],
            'message' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'string', 'max:24', 'regex:/^[a-z0-9_\-]+$/'],
            'website' => ['nullable', 'max:0'], // honeypot
        ]);

        return $this->context->run($tenant, function () use ($data) {
            $booking = $this->online->submit($data);
            $booking->load(['branch', 'service', 'practitioner']);
            $tz = $booking->branch->effectiveTimezone();

            return response()->json(['data' => [
                'reference' => $booking->reference,
                'status' => $booking->status,
                'starts_at' => $booking->requested_start->utc()->toIso8601ZuluString(),
                'local_start' => $booking->requested_start->timezone($tz)->format('Y-m-d\TH:i'),
                'branch' => $booking->branch->name,
                'service' => $booking->service?->name,
                'practitioner' => $booking->practitioner?->name,
                'message' => $booking->status === 'confirmed'
                    ? 'Your appointment is booked. We look forward to seeing you.'
                    : 'Thank you. Your request has been sent; the clinic will confirm your appointment shortly.',
            ]], 201);
        });
    }

    /**
     * Clinic logo (letterhead), for the booking page and emails.
     *
     * @unauthenticated
     */
    public function logo(string $slug): Response
    {
        $tenant = Tenant::query()->where('slug', $slug)->first();
        abort_unless($tenant?->logo_path && Storage::disk(config('filesystems.private_disk'))->exists($tenant->logo_path), 404);

        return Storage::disk(config('filesystems.private_disk'))->response($tenant->logo_path, null, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = Tenant::query()->where('slug', $slug)->first();
        abort_unless($tenant?->isActive(), 404);

        return $tenant;
    }
}
