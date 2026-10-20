<?php

namespace App\Services\Clinical;

use App\Models\Appointment;
use App\Models\Assessment;
use App\Models\ClinicalNote;
use App\Models\ConsentSignature;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\ProgressEntry;
use App\Models\TreatmentPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One chronological stream of everything that happened to a patient, limited
 * to what the viewer may see: clinical events need clinical.view, money needs
 * billing.view, messages need messages.send. Labels are built client-side
 * from "type" + "data" so the stream is language-neutral.
 */
class PatientTimeline
{
    private const LIMIT_PER_SOURCE = 200;

    /** @return list<array{key: string, type: string, at: string, status: ?string, data: array}> */
    public function for(Patient $patient, User $viewer, ?array $types = null): array
    {
        $tz = $viewer->tenant->timezone;
        $clinical = $viewer->can('clinical.view');
        $billing = $viewer->can('billing.view');
        $want = fn (string $type) => $types === null || in_array($type, $types, true);
        $events = [];

        $push = function (string $type, int|string $id, ?CarbonInterface $at, ?string $status, array $data) use (&$events) {
            if ($at) {
                $events[] = ['key' => "{$type}-{$id}", 'type' => $type, 'at' => $at->copy()->utc()->toIso8601ZuluString(), 'status' => $status, 'data' => $data];
            }
        };

        if ($want('registered')) {
            $push('registered', $patient->id, $patient->created_at, null, ['mrn' => $patient->mrn]);
        }

        if ($want('appointment')) {
            Appointment::query()->where('patient_id', $patient->id)->with(['practitioner:id,name', 'service:id,name'])
                ->latest('starts_at')->limit(self::LIMIT_PER_SOURCE)->get()
                ->each(fn (Appointment $a) => $push('appointment', $a->id, $a->starts_at, $a->status->value, [
                    'service' => $a->service?->name, 'practitioner' => $a->practitioner?->name, 'type' => $a->type->value,
                    'local_start' => $a->local_starts_at->format('Y-m-d\TH:i'),
                ]));
        }

        if ($clinical) {
            if ($want('assessment')) {
                Assessment::query()->where('patient_id', $patient->id)->with('practitioner:id,name')->latest('assessed_at')->limit(self::LIMIT_PER_SOURCE)->get()
                    ->each(fn (Assessment $a) => $push('assessment', $a->id, $a->assessed_at, $a->status->value, [
                        'chief_complaint' => $a->chief_complaint, 'pain_score' => $a->pain_score, 'practitioner' => $a->practitioner?->name,
                    ]));
            }
            if ($want('note')) {
                ClinicalNote::query()->where('patient_id', $patient->id)->with('author:id,name')->latest('created_at')->limit(self::LIMIT_PER_SOURCE)->get()
                    // A note belongs at its session time, not when it was later signed.
                    ->each(fn (ClinicalNote $n) => $push('note', $n->id, $n->created_at, $n->status->value, [
                        'pain_score' => $n->pain_score, 'author' => $n->author?->name,
                    ]));
            }
            if ($want('plan')) {
                TreatmentPlan::query()->where('patient_id', $patient->id)->with('practitioner:id,name')->latest('start_date')->get()
                    ->each(fn (TreatmentPlan $p) => $push('plan', $p->id, CarbonImmutable::parse($p->start_date->toDateString().' 09:00', $tz), $p->status->value, [
                        'title' => $p->title, 'duration_weeks' => $p->duration_weeks, 'practitioner' => $p->practitioner?->name,
                    ]));
            }
            if ($want('prescription')) {
                Prescription::query()->where('patient_id', $patient->id)->with('practitioner:id,name')->latest('issued_on')->get()
                    // Backdated prescriptions sit on their issue date; same-day ones keep their exact time.
                    ->each(fn (Prescription $p) => $push('prescription', $p->id, $p->created_at->copy()->timezone($tz)->toDateString() === $p->issued_on->toDateString()
                        ? $p->created_at
                        : CarbonImmutable::parse($p->issued_on->toDateString().' 12:00', $tz), null, [
                            'number' => $p->number, 'practitioner' => $p->practitioner?->name, 'review_on' => $p->review_on?->toDateString(),
                        ]));
            }
            if ($want('progress')) {
                ProgressEntry::query()->where('patient_id', $patient->id)->latest('recorded_on')->limit(self::LIMIT_PER_SOURCE)->get()
                    ->each(fn (ProgressEntry $e) => $push('progress', $e->id, CarbonImmutable::parse($e->recorded_on->toDateString().' 12:00', $tz), null, [
                        'pain_score' => $e->pain_score, 'function_score' => $e->function_score, 'measure' => $e->measure,
                        'value' => $e->value !== null ? (float) $e->value : null, 'unit' => $e->unit,
                    ]));
            }
            if ($want('document')) {
                Document::query()->where('patient_id', $patient->id)->latest('created_at')->limit(self::LIMIT_PER_SOURCE)->get()
                    ->each(fn (Document $d) => $push('document', $d->id, $d->created_at, null, ['name' => $d->original_name, 'category' => $d->category]));
            }
            if ($want('consent')) {
                ConsentSignature::query()->where('patient_id', $patient->id)->latest('signed_at')->get()
                    ->each(fn (ConsentSignature $c) => $push('consent', $c->id, $c->signed_at, null, ['title' => $c->title, 'signer' => $c->signer_name]));
            }
        }

        if ($billing) {
            if ($want('invoice')) {
                Invoice::query()->where('patient_id', $patient->id)->whereNotNull('posted_at')->latest('posted_at')->limit(self::LIMIT_PER_SOURCE)->get()
                    ->each(fn (Invoice $i) => $push('invoice', $i->id, $i->posted_at, $i->status->value === 'posted' ? $i->payment_status : $i->status->value, [
                        'number' => $i->number, 'total_minor' => $i->total_minor, 'currency' => $i->currency_code,
                    ]));
            }
            if ($want('payment')) {
                Payment::query()->where('patient_id', $patient->id)->latest('received_at')->limit(self::LIMIT_PER_SOURCE)->get()
                    ->each(fn (Payment $p) => $push('payment', $p->id, $p->received_at, $p->type, [
                        'amount_minor' => $p->amount_minor, 'method' => $p->method->value, 'receipt_number' => $p->receipt_number, 'invoice_id' => $p->invoice_id,
                    ]));
            }
        }

        if ($viewer->can('messages.send') && $want('message')) {
            Message::query()->where('patient_id', $patient->id)->latest('created_at')->limit(self::LIMIT_PER_SOURCE)->get()
                ->each(fn (Message $m) => $push('message', $m->id, $m->sent_at ?? $m->created_at, $m->status->value, [
                    'channel' => $m->channel->value, 'purpose' => $m->purpose,
                ]));
        }

        usort($events, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return $events;
    }
}
