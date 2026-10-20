<?php

namespace App\Http\Resources;

use App\Models\SupportTicket;
use Illuminate\Http\Request;

/** @mixin SupportTicket */
class SupportTicketResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'subject' => $this->subject,
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->status->value,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'last_reply_by' => $this->last_reply_by,
            'last_reply_at' => $this->iso($this->last_reply_at),
            'resolved_at' => $this->iso($this->resolved_at),
            'messages_count' => $this->whenCounted('messages'),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($m) => [
                'id' => $m->id,
                'author_type' => $m->author_type,
                'author_name' => $m->author_name,
                'body' => $m->body,
                'created_at' => $this->iso($m->created_at),
            ])),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
