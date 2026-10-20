<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;

/**
 * All amounts are integer minor units of `currency`.
 *
 * @mixin Invoice
 */
class InvoiceResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status,
            'currency' => $this->currency_code,
            'branch_id' => $this->branch_id,
            'patient' => $this->whenLoaded('patient', fn () => [
                'id' => $this->patient->id,
                'full_name' => $this->patient->full_name,
                'mrn' => $this->patient->mrn,
            ]),
            'issue_date' => $this->date($this->issue_date),
            'due_date' => $this->date($this->due_date),
            'subtotal_minor' => $this->subtotal_minor,
            'discount_minor' => $this->discount_minor,
            'tax_minor' => $this->tax_minor,
            'total_minor' => $this->total_minor,
            'amount_paid_minor' => $this->amount_paid_minor,
            'amount_credited_minor' => $this->amount_credited_minor,
            'balance_minor' => $this->balance_minor,
            'payer_type' => $this->payer_type,
            'insurance_policy_id' => $this->insurance_policy_id,
            'claim_status' => $this->claim_status,
            'claim_reference' => $this->claim_reference,
            'notes' => $this->notes,
            'void_reason' => $this->void_reason,
            'posted_at' => $this->iso($this->posted_at),
            'voided_at' => $this->iso($this->voided_at),
            'created_at' => $this->iso($this->created_at),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'service_id' => $item->service_id,
                'package_plan_id' => $item->package_plan_id,
                'appointment_id' => $item->appointment_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price_minor' => $item->unit_price_minor,
                'discount_minor' => $item->discount_minor,
                'tax_rate_bp' => $item->tax_rate_bp,
                'tax_minor' => $item->tax_minor,
                'total_minor' => $item->total_minor,
            ])),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'credit_notes' => $this->whenLoaded('creditNotes', fn () => $this->creditNotes->map(fn ($cn) => [
                'id' => $cn->id,
                'number' => $cn->number,
                'amount_minor' => $cn->amount_minor,
                'reason' => $cn->reason,
                'issued_at' => $this->iso($cn->issued_at),
            ])),
        ];
    }
}
