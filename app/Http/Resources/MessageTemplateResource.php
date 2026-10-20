<?php

namespace App\Http\Resources;

use App\Models\MessageTemplate;
use Illuminate\Http\Request;

/** @mixin MessageTemplate */
class MessageTemplateResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'purpose' => $this->purpose,
            'channel' => $this->channel->value,
            'subject' => $this->subject,
            'body' => $this->body,
            'locale' => $this->locale,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
        ];
    }
}
