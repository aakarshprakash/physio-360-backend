<?php

namespace App\Jobs;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Messaging\TwilioGateway;
use App\Tenancy\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Delivers one queued message and records the outcome on the log row. */
class SendMessage implements ShouldQueue
{
    use Queueable, TenantAwareJob;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $messageId)
    {
        $this->captureTenant();
    }

    public function handle(TwilioGateway $twilio): void
    {
        $message = Message::query()->find($this->messageId);
        if (! $message || $message->status !== MessageStatus::Queued) {
            return;
        }

        $tenant = Tenant::query()->findOrFail($message->tenant_id);
        $message->forceFill(['attempts' => $message->attempts + 1])->save();

        try {
            if ($message->channel === MessageChannel::Email) {
                Mail::raw($message->body, fn ($mail) => $mail->to($message->recipient)->subject($message->subject ?? $tenant->name));
                $providerId = null;
            } else {
                $providerId = $twilio->send($tenant, $message);
            }
        } catch (Throwable $e) {
            $final = $this->attempts() >= $this->tries;
            $message->forceFill([
                'status' => $final ? MessageStatus::Failed : MessageStatus::Queued,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            if (! $final) {
                throw $e; // let the queue retry with backoff
            }

            return;
        }

        $message->forceFill([
            'status' => MessageStatus::Sent,
            'provider_message_id' => $providerId,
            'error' => null,
            'sent_at' => now(),
        ])->save();
    }
}
