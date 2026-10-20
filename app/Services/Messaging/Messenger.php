<?php

namespace App\Services\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\SendMessage;
use App\Models\Message;
use App\Models\Patient;
use App\Models\Tenant;
use App\Support\PhoneNumber;
use App\Tenancy\TenantContext;

/**
 * Records and dispatches outbound messages.
 *
 * Email always goes through the app mailer. SMS / WhatsApp go through the
 * clinic's Twilio account when configured; otherwise the message is logged as
 * "manual" and the API returns a prefilled wa.me / sms: link so the front desk
 * can send it from the clinic phone. Either way the log is complete.
 */
class Messenger
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TwilioGateway $twilio,
    ) {}

    public function canDeliver(Tenant $tenant, MessageChannel $channel): bool
    {
        return $channel === MessageChannel::Email || $this->twilio->isConfigured($tenant, $channel);
    }

    /** Patient's address for a channel, normalised; null if they have none. */
    public function recipientFor(Patient $patient, MessageChannel $channel): ?string
    {
        if ($channel === MessageChannel::Email) {
            return $patient->email ?: null;
        }

        return PhoneNumber::e164($patient->phone, $this->context->require()->country_code);
    }

    /**
     * @param  array{channel: MessageChannel, body: string, purpose?: string, subject?: ?string, patient?: ?Patient,
     *               recipient?: ?string, template_id?: ?int, invoice_id?: ?int, appointment_id?: ?int, branch_id?: ?int, sent_by?: ?int}  $data
     */
    public function send(array $data, bool $allowManual = true): Message
    {
        $tenant = $this->context->require();
        $channel = $data['channel'];
        $patient = $data['patient'] ?? null;

        $recipient = $data['recipient'] ?? ($patient ? $this->recipientFor($patient, $channel) : null);
        if ($recipient && $channel !== MessageChannel::Email) {
            $recipient = PhoneNumber::e164($recipient, $tenant->country_code);
        }
        if (! $recipient) {
            throw new DomainRuleViolation($channel === MessageChannel::Email
                ? 'The patient has no email address.'
                : 'The patient has no valid mobile number.', 'no_recipient');
        }

        $deliverable = $this->canDeliver($tenant, $channel);
        if (! $deliverable && ! $allowManual) {
            throw new DomainRuleViolation('No gateway is configured for this channel.', 'channel_not_configured');
        }

        $message = Message::query()->create([
            'branch_id' => $data['branch_id'] ?? $patient?->primary_branch_id,
            'patient_id' => $patient?->id,
            'invoice_id' => $data['invoice_id'] ?? null,
            'appointment_id' => $data['appointment_id'] ?? null,
            'template_id' => $data['template_id'] ?? null,
            'channel' => $channel,
            'purpose' => $data['purpose'] ?? 'general',
            'recipient' => $recipient,
            'subject' => $channel === MessageChannel::Email ? ($data['subject'] ?? $tenant->name) : null,
            'body' => $data['body'],
            'status' => $deliverable ? MessageStatus::Queued : MessageStatus::Manual,
            'provider' => $deliverable ? ($channel === MessageChannel::Email ? 'mail' : TwilioGateway::NAME) : 'manual',
            'sent_by' => $data['sent_by'] ?? null,
            'sent_at' => $deliverable ? null : now(),
        ]);

        if ($deliverable) {
            SendMessage::dispatch($message->id)->afterCommit();
        }

        return $message;
    }

    /** Prefilled link for sending a "manual" message from the clinic's own phone. */
    public function shareUrl(Message $message): ?string
    {
        if ($message->status !== MessageStatus::Manual) {
            return null;
        }

        return match ($message->channel) {
            MessageChannel::WhatsApp => 'https://wa.me/'.ltrim($message->recipient, '+').'?text='.rawurlencode($message->body),
            MessageChannel::Sms => 'sms:'.$message->recipient.'?body='.rawurlencode($message->body),
            MessageChannel::Email => null,
        };
    }
}
