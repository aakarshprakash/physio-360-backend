<?php

namespace App\Http\Resources;

use App\Models\ConsentSignature;
use Illuminate\Http\Request;

/** @mixin ConsentSignature */
class ConsentSignatureResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'consent_template_id' => $this->consent_template_id,
            'template_version' => $this->template_version,
            'title' => $this->title,
            'body_snapshot' => $this->body_snapshot,
            'signer_name' => $this->signer_name,
            'signer_relationship' => $this->signer_relationship,
            'signed_via' => $this->signed_via,
            'witness' => $this->whenLoaded('witness', fn () => $this->witness?->name),
            'content_hash' => $this->content_hash,
            'signed_at' => $this->iso($this->signed_at),
        ];
    }
}
