<?php

namespace App\Services\Scheduling;

use App\Exceptions\DomainRuleViolation;
use App\Models\Branch;
use App\Models\OnlineBooking;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PatientService;
use App\Support\BlindIndex;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public self-booking (shareable link, website embed, WhatsApp). Visitors pick
 * a real free slot; the clinic either confirms requests from an inbox
 * ("request" mode, the default) or lets them book instantly.
 */
class OnlineBookingService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AvailabilityService $availability,
        private readonly BookingService $booking,
        private readonly PatientService $patients,
    ) {}

    public function settings(?Tenant $tenant = null): array
    {
        $tenant ??= $this->context->require();

        return [
            'enabled' => (bool) $tenant->setting('online_booking.enabled', true),
            'mode' => (string) $tenant->setting('online_booking.mode', 'request'),
            'min_lead_minutes' => (int) $tenant->setting('online_booking.min_lead_minutes', 120),
            'max_days_ahead' => (int) $tenant->setting('online_booking.max_days_ahead', 30),
            'allow_practitioner_choice' => (bool) $tenant->setting('online_booking.allow_practitioner_choice', true),
            'intro' => $tenant->setting('online_booking.intro'),
        ];
    }

    /** Practitioners who take online bookings at a branch. */
    public function practitioners(?int $branchId = null): Collection
    {
        return User::query()
            ->where('is_active', true)->where('is_practitioner', true)->where('online_bookable', true)
            ->when($branchId, fn ($q) => $q->whereHas('branches', fn ($b) => $b->whereKey($branchId)))
            ->with('branches:id')
            ->orderBy('name')
            ->get();
    }

    public function services(): Collection
    {
        return Service::query()->where('is_active', true)->where('online_bookable', true)->orderBy('category')->orderBy('name')->get();
    }

    /**
     * Free slots on a local date. Without a practitioner, slots from every
     * eligible practitioner are merged ("any available").
     *
     * @return list<array{local_start: string, starts_at: string, practitioner_ids: list<int>}>
     */
    public function slots(Branch $branch, Service $service, CarbonImmutable $localDate, ?int $practitionerId = null): array
    {
        $settings = $this->settings();
        $tz = $branch->effectiveTimezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $date = CarbonImmutable::parse($localDate->toDateString(), $tz);

        if ($date->lessThan($today) || $date->greaterThan($today->addDays($settings['max_days_ahead']))) {
            return [];
        }

        $practitioners = $this->practitioners($branch->id)
            ->when($practitionerId !== null, fn ($c) => $c->where('id', $practitionerId));

        $merged = [];
        foreach ($practitioners as $p) {
            foreach ($this->availability->slots($p->id, $branch->id, $date, $tz, $service->duration_minutes, $settings['min_lead_minutes']) as $slot) {
                $merged[$slot['local_start']] ??= $slot + ['practitioner_ids' => []];
                $merged[$slot['local_start']]['practitioner_ids'][] = $p->id;
            }
        }
        ksort($merged);

        return array_values($merged);
    }

    /**
     * Records a visitor's request for a slot that is free right now. In
     * instant mode it is confirmed into an appointment straight away.
     *
     * @param  array{branch_id:int, service_id:int, practitioner_id:?int, local_start:string, name:string, phone:string, email:?string, gender:?string, age:?int, message:?string, source:?string}  $data
     */
    public function submit(array $data): OnlineBooking
    {
        $settings = $this->settings();
        if (! $settings['enabled']) {
            throw new DomainRuleViolation('Online booking is currently unavailable. Please call the clinic.', 'booking_disabled');
        }

        $branch = Branch::query()->where('is_active', true)->findOrFail($data['branch_id']);
        $service = $this->services()->firstWhere('id', $data['service_id']) ?? abort(404);
        $practitionerId = $settings['allow_practitioner_choice'] ? ($data['practitioner_id'] ?? null) : null;
        $tz = $branch->effectiveTimezone();
        $localStart = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['local_start'], $tz)->startOfMinute();

        $slot = collect($this->slots($branch, $service, $localStart->startOfDay(), $practitionerId))->firstWhere('local_start', $localStart->format('Y-m-d\TH:i'));
        if (! $slot) {
            throw new DomainRuleViolation('That time is no longer available. Please choose another slot.', 'slot_unavailable');
        }

        // Light abuse guard: one open request per phone number per day and slot window.
        $phoneIndex = BlindIndex::phone($data['phone']);
        $duplicate = OnlineBooking::query()->where('phone_index', $phoneIndex)->where('status', 'pending')->where('created_at', '>', now()->subDay())->count();
        if ($duplicate >= 3) {
            throw new DomainRuleViolation('You already have requests waiting for the clinic. They will contact you shortly.', 'too_many_requests');
        }

        $request = DB::transaction(fn () => OnlineBooking::query()->create([
            'reference' => $this->reference(),
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'practitioner_id' => $practitionerId ?? $slot['practitioner_ids'][0],
            'requested_start' => $localStart->utc(),
            'duration_minutes' => $service->duration_minutes,
            'name' => trim($data['name']),
            'phone' => trim($data['phone']),
            'email' => $data['email'] ?? null,
            'gender' => $data['gender'] ?? null,
            'age' => $data['age'] ?? null,
            'message' => $data['message'] ?? null,
            'source' => $data['source'] ?? 'link',
        ]));

        if ($settings['mode'] === 'instant') {
            try {
                return $this->confirm($request, null);
            } catch (SlotConflict) {
                $request->forceFill(['status' => 'cancelled', 'decision_note' => 'Slot taken while booking'])->save();
                throw new DomainRuleViolation('That time was just taken. Please choose another slot.', 'slot_unavailable');
            }
        }

        return $request;
    }

    /**
     * Turns a request into an appointment: links an existing patient (given,
     * or the single exact phone match) or registers a new one.
     *
     * @param  array{patient_id?: ?int, practitioner_id?: ?int, local_start?: ?string, new_patient?: bool}  $overrides
     */
    public function confirm(OnlineBooking $request, ?User $actor, array $overrides = []): OnlineBooking
    {
        return DB::transaction(function () use ($request, $actor, $overrides) {
            $locked = OnlineBooking::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new DomainRuleViolation('This request has already been '.$locked->status.'.', 'request_closed');
            }

            $branch = Branch::query()->findOrFail($locked->branch_id);
            $tz = $branch->effectiveTimezone();
            $localStart = ! empty($overrides['local_start'])
                ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $overrides['local_start'], $tz)
                : $locked->requested_start->toImmutable()->setTimezone($tz);

            $patient = $this->resolvePatient($locked, $overrides['patient_id'] ?? null, (bool) ($overrides['new_patient'] ?? false));

            $appointment = $this->booking->book(new BookingRequest(
                patientId: $patient->id,
                practitionerId: (int) ($overrides['practitioner_id'] ?? $locked->practitioner_id),
                branchId: $branch->id,
                localStart: $localStart,
                durationMinutes: $locked->duration_minutes,
                timezone: $tz,
                serviceId: $locked->service_id,
                notes: $locked->message ? 'Online booking: '.$locked->message : 'Online booking '.$locked->reference,
                bookedVia: 'online',
            ), $actor?->id);

            $locked->forceFill([
                'status' => 'confirmed',
                'patient_id' => $patient->id,
                'appointment_id' => $appointment->id,
                'decided_by' => $actor?->id,
                'decided_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function decline(OnlineBooking $request, User $actor, ?string $note): OnlineBooking
    {
        return DB::transaction(function () use ($request, $actor, $note) {
            $locked = OnlineBooking::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new DomainRuleViolation('This request has already been '.$locked->status.'.', 'request_closed');
            }
            $locked->forceFill(['status' => 'declined', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note])->save();

            return $locked;
        });
    }

    /** Existing patients with the same phone number (exact match after decryption). */
    public function matchingPatients(OnlineBooking $request): Collection
    {
        if (! $request->phone_index) {
            return collect();
        }

        return Patient::query()->where('phone_index', $request->phone_index)->limit(5)->get()
            ->filter(fn (Patient $p) => BlindIndex::phonesMatch($p->phone, $request->phone))
            ->values();
    }

    private function resolvePatient(OnlineBooking $request, ?int $patientId, bool $forceNew = false): Patient
    {
        if ($patientId) {
            return Patient::query()->findOrFail($patientId);
        }

        $matches = $forceNew ? collect() : $this->matchingPatients($request);
        $byName = $matches->filter(fn (Patient $p) => Str::lower(trim($p->full_name)) === Str::lower($request->name));
        if ($byName->count() === 1) {
            return $byName->first();
        }
        if ($matches->count() === 1 && $byName->isEmpty() && Str::lower(Str::before($matches->first()->full_name, ' ')) === Str::lower(Str::before($request->name, ' '))) {
            return $matches->first();
        }

        $parts = preg_split('/\s+/', trim($request->name), 2);

        return $this->patients->create([
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? null,
            'phone' => $request->phone,
            'email' => $request->email,
            'gender' => $request->gender,
            'primary_branch_id' => $request->branch_id,
            'primary_practitioner_id' => $request->practitioner_id,
            'referred_by' => 'Online booking ('.$request->source.')',
            'notes' => $request->age ? 'Age '.$request->age.' at online booking' : null,
        ]);
    }

    private function reference(): string
    {
        do {
            $ref = 'BK-'.strtoupper(Str::random(6));
        } while (OnlineBooking::query()->where('reference', $ref)->exists());

        return $ref;
    }
}
