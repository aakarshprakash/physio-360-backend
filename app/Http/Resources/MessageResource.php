<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Services\Messaging\Messenger;
use Illuminate\Http\Request;

/** @mixin Message */
class MessageResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel->value,
            'purpose' => $this->purpose,
            'recipient' => $this->recipient,
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => $this->status->value,
            'provider' => $this->provider,
            'error' => $this->error,
            'patient' => $this->whenLoaded('patient', fn () => $this->patient ? ['id' => $this->patient->id, 'full_name' => $this->patient->full_name, 'mrn' => $this->patient->mrn] : null),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? ['id' => $this->invoice->id, 'number' => $this->invoice->number] : null),
            /** Staff member who sent it; null for automatic messages. */
            'sent_by' => $this->whenLoaded('sender', fn () => $this->sender?->name),
            /** Prefilled WhatsApp / SMS link for "manual" messages. */
            'share_url' => app(Messenger::class)->shareUrl($this->resource),
            'sent_at' => $this->iso($this->sent_at),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
