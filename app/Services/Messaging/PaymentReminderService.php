<?php

namespace App\Services\Messaging;

use App\Enums\InvoiceStatus;
use App\Enums\MessageChannel;
use App\Exceptions\DomainRuleViolation;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Tenant;
use App\Services\Payments\RazorpayGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Reminders for posted invoices with a balance: sent by staff from the
 * reminders page, or automatically (settings.payment_reminders) on chosen
 * days after the due date.
 */
class PaymentReminderService
{
    public const DEFAULT_TEXT = 'Dear {{patient_name}}, this is a reminder from {{clinic_name}} that {{amount_due}} is due on invoice {{invoice_number}}. {{payment_link}} Thank you.';

    public function __construct(
        private readonly MessageComposer $composer,
        private readonly Messenger $messenger,
        private readonly RazorpayGateway $razorpay,
    ) {}

    /** Posted invoices with money still owed. */
    public function owing(): Builder
    {
        return Invoice::query()
            ->where('status', InvoiceStatus::Posted)
            ->whereRaw('total_minor - amount_paid_minor - amount_credited_minor > 0');
    }

    /** owing() plus reminder history columns. */
    public function outstanding(): Builder
    {
        return $this->owing()
            ->addSelect(['invoices.*',
                'last_reminded_at' => Message::query()->selectRaw('MAX(created_at)')
                    ->whereColumn('messages.invoice_id', 'invoices.id')->where('purpose', 'payment_reminder'),
                'reminder_count' => Message::query()->selectRaw('COUNT(*)')
                    ->whereColumn('messages.invoice_id', 'invoices.id')->where('purpose', 'payment_reminder'),
            ]);
    }

    public function remind(Invoice $invoice, MessageChannel $channel, ?int $actorId, bool $withPaymentLink = false, ?int $templateId = null, bool $allowManual = true): Message
    {
        $invoice->loadMissing(['patient', 'tenant']);
        $tenant = $invoice->tenant;

        if ($invoice->status !== InvoiceStatus::Posted || $invoice->balance_minor <= 0) {
            throw new DomainRuleViolation('Only posted invoices with a balance can be reminded.', 'nothing_due');
        }

        $link = null;
        if ($withPaymentLink && $this->razorpay->isConfigured($tenant)) {
            $link = $invoice->paymentLinks()->where('status', 'created')->where('amount_minor', $invoice->balance_minor)
                ->where('expires_at', '>', now()->addDay())->latest('id')->value('short_url')
                ?? $this->razorpay->createPaymentLink($tenant, $invoice, $invoice->balance_minor)->short_url;
        }

        $template = $templateId
            ? MessageTemplate::query()->findOrFail($templateId)
            : $this->composer->templateFor('payment_reminder', $channel, $invoice->patient->preferred_language);

        $vars = $this->composer->variables($tenant, $invoice->patient, invoice: $invoice, paymentLink: $link ? "Pay online: {$link}" : null);

        return $this->messenger->send([
            'channel' => $channel,
            'purpose' => 'payment_reminder',
            'patient' => $invoice->patient,
            'invoice_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
            'template_id' => $template?->id,
            'subject' => $template?->subject ? $this->composer->render($template->subject, $vars) : "Payment reminder: {$invoice->number}",
            'body' => trim(preg_replace('/\s{2,}/', ' ', $this->composer->render($template?->body ?? self::DEFAULT_TEXT, $vars))),
            'sent_by' => $actorId,
        ], $allowManual);
    }

    /**
     * Automatic run for one tenant (inside its context). Sends on the configured
     * days after the due date, at most once per invoice per day, only through
     * channels that can deliver without staff.
     *
     * @return int reminders sent
     */
    public function sendScheduled(Tenant $tenant): int
    {
        $config = (array) $tenant->setting('payment_reminders', []);
        if (! ($config['enabled'] ?? false)) {
            return 0;
        }

        $channel = MessageChannel::tryFrom($config['channel'] ?? 'email') ?? MessageChannel::Email;
        if (! $this->messenger->canDeliver($tenant, $channel)) {
            return 0;
        }

        $steps = array_map('intval', $config['days_after_due'] ?? [1, 7, 14]);
        $sent = 0;

        $this->outstanding()->whereNotNull('due_date')->with(['patient', 'tenant'])->each(function (Invoice $invoice) use ($steps, $tenant, $channel, $config, &$sent) {
            if (! in_array($invoice->daysOverdue($tenant->timezone), $steps, true)) {
                return;
            }
            if ($invoice->last_reminded_at && CarbonImmutable::parse($invoice->last_reminded_at)->greaterThan(now()->subHours(20))) {
                return;
            }

            try {
                $this->remind($invoice, $channel, null, (bool) ($config['include_payment_link'] ?? false), allowManual: false);
                $sent++;
            } catch (DomainRuleViolation) {
                // No contact for this channel or nothing due any more: skip quietly.
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $sent;
    }
}
