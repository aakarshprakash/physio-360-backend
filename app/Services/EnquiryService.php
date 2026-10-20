<?php

namespace App\Services;

use App\Enums\EnquiryStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Enquiry;
use App\Models\EnquiryActivity;
use App\Models\Patient;
use App\Support\BlindIndex;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Enquiry (lead) pipeline: capture -> contact/follow-up -> convert to patient or mark lost.
 * Every change leaves an append-only activity entry so the front desk sees the full history.
 */
class EnquiryService
{
    public const CONTACT_TYPES = ['call', 'whatsapp', 'email', 'sms', 'visit'];

    public function __construct(private readonly PatientService $patients) {}

    public function create(array $data, ?int $actorId): Enquiry
    {
        return DB::transaction(function () use ($data, $actorId) {
            $enquiry = new Enquiry($data);
            $enquiry->created_by = $actorId;
            $enquiry->save();

            $this->activity($enquiry, 'note', 'Enquiry received via '.str_replace('_', ' ', $enquiry->source).'.', $actorId);

            if ($enquiry->next_follow_up_at) {
                $enquiry->forceFill(['status' => EnquiryStatus::FollowUp])->save();
            }

            return $enquiry->refresh();
        });
    }

    /**
     * Log an interaction. Contact types move a new enquiry to "contacted";
     * scheduling a follow-up moves an open enquiry to "follow_up".
     */
    public function logActivity(Enquiry $enquiry, string $type, ?string $body, ?int $actorId, ?CarbonInterface $nextFollowUpAt = null): Enquiry
    {
        return DB::transaction(function () use ($enquiry, $type, $body, $actorId, $nextFollowUpAt) {
            $locked = Enquiry::query()->whereKey($enquiry->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen() && in_array($type, self::CONTACT_TYPES, true)) {
                throw new DomainRuleViolation('Reopen the enquiry before logging new contact.', 'enquiry_closed');
            }

            $this->activity($locked, $type, $body, $actorId);

            if (in_array($type, self::CONTACT_TYPES, true)) {
                $locked->last_contacted_at = now();
                if ($locked->status === EnquiryStatus::New) {
                    $this->setStatus($locked, EnquiryStatus::Contacted, $actorId);
                }
            }

            if ($nextFollowUpAt !== null && $locked->status->isOpen()) {
                $locked->next_follow_up_at = $nextFollowUpAt;
                $this->activity($locked, 'follow_up', 'Follow-up scheduled', $actorId, ['at' => $nextFollowUpAt->toIso8601ZuluString()]);
                if ($locked->status !== EnquiryStatus::FollowUp) {
                    $this->setStatus($locked, EnquiryStatus::FollowUp, $actorId);
                }
            }

            $locked->save();

            return $locked;
        });
    }

    public function transition(Enquiry $enquiry, EnquiryStatus $to, ?string $reason, ?int $actorId): Enquiry
    {
        return DB::transaction(function () use ($enquiry, $to, $reason, $actorId) {
            $locked = Enquiry::query()->whereKey($enquiry->id)->lockForUpdate()->firstOrFail();

            if ($to === EnquiryStatus::Converted) {
                throw new DomainRuleViolation('Use "Convert to patient" to convert an enquiry.', 'use_convert');
            }
            if (! $locked->status->canTransitionTo($to)) {
                throw new DomainRuleViolation("Cannot move an enquiry from {$locked->status->value} to {$to->value}.", 'invalid_transition');
            }
            if ($to === EnquiryStatus::Lost && blank($reason)) {
                throw new DomainRuleViolation('Please give a reason when marking an enquiry as lost.', 'reason_required');
            }

            $locked->lost_reason = $to === EnquiryStatus::Lost ? $reason : null;
            if (! $to->isOpen()) {
                $locked->next_follow_up_at = null;
            }
            $this->setStatus($locked, $to, $actorId, $reason);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Register the enquirer as a patient (or link an existing patient) and close the enquiry.
     *
     * @param  array<string, mixed>  $patientData  overrides for the new patient record
     */
    public function convert(Enquiry $enquiry, array $patientData, ?int $existingPatientId, ?int $actorId): Patient
    {
        return DB::transaction(function () use ($enquiry, $patientData, $existingPatientId, $actorId) {
            $locked = Enquiry::query()->whereKey($enquiry->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw new DomainRuleViolation('Only open enquiries can be converted.', 'enquiry_closed');
            }

            if ($existingPatientId !== null) {
                $patient = Patient::query()->findOrFail($existingPatientId);
            } else {
                [$first, $last] = $this->splitName($locked->name);
                $patient = $this->patients->create(array_merge([
                    'first_name' => $first,
                    'last_name' => $last,
                    'phone' => $locked->phone,
                    'email' => $locked->email,
                    'primary_branch_id' => $locked->branch_id,
                    'referred_by' => 'Enquiry ('.str_replace('_', ' ', $locked->source).')',
                    'notes' => $locked->interest,
                ], array_filter($patientData, fn ($v) => $v !== null && $v !== '')));
            }

            $locked->forceFill([
                'status' => EnquiryStatus::Converted,
                'converted_patient_id' => $patient->id,
                'converted_at' => now(),
                'next_follow_up_at' => null,
            ])->save();

            $this->activity($locked, 'converted', 'Registered as patient '.$patient->mrn, $actorId, ['patient_id' => $patient->id]);

            return $patient;
        });
    }

    /** Existing patients with the same phone number (exact match after decryption). */
    public function matchingPatients(Enquiry $enquiry): Collection
    {
        if (! $enquiry->phone_index) {
            return collect();
        }

        return Patient::query()
            ->where('phone_index', $enquiry->phone_index)
            ->limit(5)
            ->get()
            ->filter(fn (Patient $p) => BlindIndex::phonesMatch($p->phone, $enquiry->phone))
            ->values();
    }

    private function setStatus(Enquiry $enquiry, EnquiryStatus $to, ?int $actorId, ?string $reason = null): void
    {
        $from = $enquiry->status;
        $enquiry->forceFill(['status' => $to]);
        $this->activity($enquiry, 'status_change', $reason, $actorId, ['from' => $from->value, 'to' => $to->value]);
    }

    private function activity(Enquiry $enquiry, string $type, ?string $body, ?int $actorId, array $meta = []): EnquiryActivity
    {
        return EnquiryActivity::query()->create([
            'enquiry_id' => $enquiry->id,
            'user_id' => $actorId,
            'type' => $type,
            'body' => $body,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }

    /** @return array{0: string, 1: string|null} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0], $parts[1] ?? null];
    }
}
