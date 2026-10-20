<?php

namespace App\Services\Messaging;

use App\Enums\MessageChannel;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\MessageTemplate;
use App\Models\Patient;
use App\Models\Tenant;
use App\Support\Money;

/** Fills {{placeholders}} in template text from a patient / appointment / invoice context. */
class MessageComposer
{
    public const PLACEHOLDERS = [
        'patient_name', 'patient_first_name', 'clinic_name', 'clinic_phone', 'branch_name',
        'appointment_date', 'appointment_time', 'practitioner_name',
        'invoice_number', 'amount_due', 'due_date', 'days_overdue', 'payment_link',
    ];

    public function render(string $text, array $variables): string
    {
        return preg_replace_callback('/{{\s*([a-z_]+)\s*}}/', fn ($m) => (string) ($variables[$m[1]] ?? ''), $text);
    }

    /** @return array<string, string> */
    public function variables(Tenant $tenant, ?Patient $patient = null, ?Appointment $appointment = null, ?Invoice $invoice = null, ?string $paymentLink = null): array
    {
        $branch = Branch::query()->find($appointment?->branch_id ?? $invoice?->branch_id ?? $patient?->primary_branch_id);
        $vars = [
            'patient_name' => $patient?->full_name ?? '',
            'patient_first_name' => $patient?->first_name ?? '',
            'clinic_name' => $tenant->name,
            'clinic_phone' => $branch?->phone ?? $tenant->phone ?? '',
            'branch_name' => $branch?->name ?? '',
            'payment_link' => $paymentLink ?? '',
        ];

        if ($appointment) {
            $local = $appointment->local_starts_at;
            $vars += [
                'appointment_date' => $local->format('D, d M Y'),
                'appointment_time' => $local->format('h:i A'),
                'practitioner_name' => $appointment->practitioner?->name ?? '',
            ];
        }

        if ($invoice) {
            $vars += [
                'invoice_number' => (string) $invoice->number,
                'amount_due' => Money::format(max(0, $invoice->balance_minor), $invoice->currency_code, $tenant->country_code === 'IN' ? 'en_IN' : 'en'),
                'due_date' => $invoice->due_date?->format('d M Y') ?? '',
                'days_overdue' => (string) $invoice->daysOverdue($tenant->timezone),
            ];
        }

        return $vars;
    }

    /** Clinic default for a purpose and channel, preferring the patient's language. */
    public function templateFor(string $purpose, MessageChannel $channel, ?string $locale = null): ?MessageTemplate
    {
        return MessageTemplate::query()
            ->where('purpose', $purpose)
            ->where('channel', $channel)
            ->where('is_active', true)
            ->orderByRaw('locale = ? DESC', [$locale ?? 'en'])
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
