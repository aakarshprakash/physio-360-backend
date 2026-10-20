<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;

/** @mixin Payment */
class PaymentResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn () => $this->invoice->number),
            'patient' => $this->whenLoaded('patient', fn () => ['id' => $this->patient->id, 'full_name' => $this->patient->full_name]),
            'type' => $this->type,
            'receipt_number' => $this->receipt_number,
            'amount_minor' => $this->amount_minor,
            'method' => $this->method->value,
            'reference' => $this->reference,
            'gateway' => $this->gateway,
            'received_at' => $this->iso($this->received_at),
            'received_by' => $this->whenLoaded('receiver', fn () => $this->receiver?->name),
            'notes' => $this->notes,
        ];
    }
}
