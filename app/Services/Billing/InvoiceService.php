<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\DomainRuleViolation;
use App\Models\Branch;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\PackagePlan;
use App\Models\Payment;
use App\Models\Service;
use App\Services\Sequences\SequenceAllocator;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Invoice lifecycle. All state changes lock the invoice row and run in one
 * transaction with their side effects (sequence numbers, packages, payments).
 */
class InvoiceService
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly PackageService $packages,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  array{patient_id:int, branch_id:int, items:list<array>, due_date?:string|null, notes?:string|null,
     *               payer_type?:string, insurance_policy_id?:int|null}  $data
     */
    public function createDraft(array $data, ?int $actorId): Invoice
    {
        return DB::transaction(function () use ($data, $actorId) {
            $invoice = Invoice::query()->create([
                'patient_id' => $data['patient_id'],
                'branch_id' => $data['branch_id'],
                'currency_code' => $this->tenants->require()->currency_code,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'payer_type' => $data['payer_type'] ?? 'self',
                'insurance_policy_id' => $data['insurance_policy_id'] ?? null,
                'created_by' => $actorId,
            ]);
            $invoice->forceFill(['status' => InvoiceStatus::Draft]);

            $this->replaceItems($invoice, $data['items']);

            return $invoice->refresh();
        });
    }

    public function updateDraft(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $locked = $this->lockDraft($invoice);
            $locked->fill(array_intersect_key($data, array_flip(['patient_id', 'branch_id', 'due_date', 'notes', 'payer_type', 'insurance_policy_id'])))->save();

            if (array_key_exists('items', $data)) {
                $this->replaceItems($locked, $data['items']);
            }

            return $locked->refresh();
        });
    }

    public function deleteDraft(Invoice $invoice): void
    {
        DB::transaction(fn () => $this->lockDraft($invoice)->delete());
    }

    /**
     * @param  string|null  $issueDate  Y-m-d; defaults to today in the branch timezone.
     *                                  May be backdated up to 30 days (late posting), never future-dated.
     */
    public function post(Invoice $invoice, int $actorId, ?string $issueDate = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $actorId, $issueDate) {
            $locked = $this->lockDraft($invoice);

            if ($locked->items()->count() === 0) {
                throw new DomainRuleViolation('An invoice needs at least one line before it can be posted.', 'empty_invoice');
            }

            $branch = Branch::query()->findOrFail($locked->branch_id);
            $today = CarbonImmutable::now($branch->effectiveTimezone())->startOfDay();
            $issue = $issueDate ? CarbonImmutable::parse($issueDate, $branch->effectiveTimezone())->startOfDay() : $today;

            if ($issue->greaterThan($today) || $issue->lessThan($today->subDays(30))) {
                throw new DomainRuleViolation('Issue date must be within the last 30 days and not in the future.', 'invalid_issue_date');
            }

            $locked->forceFill([
                'number' => $this->sequences->next('invoice', 'branch:'.$branch->id, 'INV-'.$branch->code.'-', 6),
                'status' => InvoiceStatus::Posted,
                'issue_date' => $issue->toDateString(),
                'due_date' => $locked->due_date ?? $issue->toDateString(),
                'posted_at' => now(),
                'posted_by' => $actorId,
            ])->save();

            $this->packages->onInvoicePosted($locked);

            return $locked;
        }, attempts: 3); // retry on deadlock: the whole posting (number included) is re-run atomically
    }

    public function void(Invoice $invoice, string $reason, int $actorId): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $actorId) {
            $locked = $this->lock($invoice);
            $this->assertTransition($locked, InvoiceStatus::Void);

            if ($locked->amount_paid_minor !== 0) {
                throw new DomainRuleViolation('Refund all payments before voiding this invoice, or issue a credit note.', 'has_payments');
            }
            if ($locked->amount_credited_minor !== 0) {
                throw new DomainRuleViolation('An invoice with credit notes cannot be voided.', 'has_credit_notes');
            }

            $this->packages->cancelForInvoice($locked, $actorId);

            $locked->forceFill([
                'status' => InvoiceStatus::Void,
                'voided_at' => now(),
                'voided_by' => $actorId,
                'void_reason' => $reason,
            ])->save();

            return $locked;
        });
    }

    public function issueCreditNote(Invoice $invoice, int $amountMinor, string $reason, int $actorId): CreditNote
    {
        return DB::transaction(function () use ($invoice, $amountMinor, $reason, $actorId) {
            $locked = $this->lock($invoice);

            if ($locked->status !== InvoiceStatus::Posted) {
                throw new DomainRuleViolation('Credit notes can only be issued against posted invoices.', 'invalid_state');
            }

            $creditable = $locked->total_minor - $locked->amount_credited_minor;
            if ($amountMinor <= 0 || $amountMinor > $creditable) {
                throw new DomainRuleViolation('Credit amount must be positive and no more than the uncredited total.', 'invalid_amount', ['max_minor' => $creditable]);
            }

            $branch = Branch::query()->findOrFail($locked->branch_id);
            $note = CreditNote::query()->create([
                'branch_id' => $branch->id,
                'invoice_id' => $locked->id,
                'number' => $this->sequences->next('credit_note', 'branch:'.$branch->id, 'CN-'.$branch->code.'-', 6),
                'amount_minor' => $amountMinor,
                'reason' => $reason,
                'issued_at' => now(),
                'created_by' => $actorId,
            ]);

            $credited = $locked->amount_credited_minor + $amountMinor;
            $locked->forceFill(['amount_credited_minor' => $credited]);

            if ($credited === $locked->total_minor) {
                $this->packages->cancelForInvoice($locked, $actorId);
                $locked->status = InvoiceStatus::Credited;
            }
            $locked->save();

            return $note;
        });
    }

    public function recordPayment(Invoice $invoice, int $amountMinor, PaymentMethod $method, array $meta = [], bool $allowOverpayment = false): Payment
    {
        return DB::transaction(function () use ($invoice, $amountMinor, $method, $meta, $allowOverpayment) {
            if (isset($meta['gateway_payment_id'])) {
                $existing = Payment::query()
                    ->where('gateway', $meta['gateway'] ?? null)
                    ->where('gateway_payment_id', $meta['gateway_payment_id'])
                    ->first();
                if ($existing) {
                    return $existing; // idempotent webhook delivery
                }
            }

            $locked = $this->lock($invoice);

            if ($locked->status !== InvoiceStatus::Posted) {
                throw new DomainRuleViolation('Payments can only be recorded against posted invoices.', 'invalid_state');
            }
            if ($amountMinor <= 0) {
                throw new DomainRuleViolation('Payment amount must be positive.', 'invalid_amount');
            }
            if (! $allowOverpayment && $amountMinor > $locked->balance_minor) {
                throw new DomainRuleViolation('Payment exceeds the outstanding balance.', 'overpayment', ['balance_minor' => $locked->balance_minor]);
            }

            $branch = Branch::query()->findOrFail($locked->branch_id);

            $payment = Payment::query()->create([
                'branch_id' => $locked->branch_id,
                'patient_id' => $locked->patient_id,
                'invoice_id' => $locked->id,
                'type' => 'payment',
                'receipt_number' => $this->sequences->next('receipt', 'branch:'.$branch->id, 'RCT-'.$branch->code.'-', 6),
                'amount_minor' => $amountMinor,
                'method' => $method,
                'reference' => $meta['reference'] ?? null,
                'gateway' => $meta['gateway'] ?? null,
                'gateway_payment_id' => $meta['gateway_payment_id'] ?? null,
                'received_at' => $meta['received_at'] ?? now(),
                'received_by' => $meta['received_by'] ?? null,
                'notes' => $meta['notes'] ?? null,
            ]);

            $locked->forceFill(['amount_paid_minor' => $locked->amount_paid_minor + $amountMinor])->save();

            return $payment;
        });
    }

    public function refund(Invoice $invoice, int $amountMinor, PaymentMethod $method, string $reason, int $actorId): Payment
    {
        return DB::transaction(function () use ($invoice, $amountMinor, $method, $reason, $actorId) {
            $locked = $this->lock($invoice);

            if ($amountMinor <= 0 || $amountMinor > $locked->amount_paid_minor) {
                throw new DomainRuleViolation('Refund must be positive and no more than the amount paid.', 'invalid_amount', ['max_minor' => $locked->amount_paid_minor]);
            }

            $payment = Payment::query()->create([
                'branch_id' => $locked->branch_id,
                'patient_id' => $locked->patient_id,
                'invoice_id' => $locked->id,
                'type' => 'refund',
                'amount_minor' => $amountMinor,
                'method' => $method,
                'received_at' => now(),
                'received_by' => $actorId,
                'notes' => $reason,
            ]);

            $locked->forceFill(['amount_paid_minor' => $locked->amount_paid_minor - $amountMinor])->save();

            return $payment;
        });
    }

    public function updateClaim(Invoice $invoice, string $status, ?string $reference): Invoice
    {
        if ($invoice->payer_type !== 'insurance') {
            throw new DomainRuleViolation('Only insurance invoices have claims.', 'not_insurance');
        }

        $invoice->forceFill(['claim_status' => $status, 'claim_reference' => $reference ?? $invoice->claim_reference])->save();

        return $invoice;
    }

    /** @param list<array> $items */
    private function replaceItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();

        $subtotal = $discount = $tax = $total = 0;

        foreach (array_values($items) as $index => $line) {
            $service = isset($line['service_id']) ? Service::query()->findOrFail($line['service_id']) : null;
            $plan = isset($line['package_plan_id']) ? PackagePlan::query()->findOrFail($line['package_plan_id']) : null;

            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $unit = (int) ($line['unit_price_minor'] ?? $plan?->price_minor ?? $service?->price_minor ?? 0);
            $rate = (int) ($line['tax_rate_bp'] ?? ($plan ? 0 : $service?->tax_rate_bp) ?? 0);
            $gross = $unit * $quantity;
            $lineDiscount = (int) ($line['discount_minor'] ?? 0);

            if ($unit < 0 || $lineDiscount < 0 || $lineDiscount > $gross) {
                throw new DomainRuleViolation('Line '.($index + 1).': discount must be between 0 and the line amount.', 'invalid_line');
            }

            $net = $gross - $lineDiscount;
            $lineTax = Money::taxFor($net, $rate);

            $invoice->items()->create([
                'service_id' => $service?->id,
                'package_plan_id' => $plan?->id,
                'appointment_id' => $line['appointment_id'] ?? null,
                'description' => $line['description'] ?? $plan?->name ?? $service?->name ?? 'Item',
                'quantity' => $quantity,
                'unit_price_minor' => $unit,
                'discount_minor' => $lineDiscount,
                'tax_rate_bp' => $rate,
                'tax_minor' => $lineTax,
                'total_minor' => $net + $lineTax,
                'sort_order' => $index,
            ]);

            $subtotal += $gross;
            $discount += $lineDiscount;
            $tax += $lineTax;
            $total += $net + $lineTax;
        }

        $invoice->forceFill([
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'tax_minor' => $tax,
            'total_minor' => $total,
        ])->save();
    }

    private function lock(Invoice $invoice): Invoice
    {
        return Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
    }

    private function lockDraft(Invoice $invoice): Invoice
    {
        $locked = $this->lock($invoice);

        if ($locked->status !== InvoiceStatus::Draft) {
            throw new DomainRuleViolation('Only draft invoices can be changed.', 'not_draft');
        }

        return $locked;
    }

    private function assertTransition(Invoice $invoice, InvoiceStatus $to): void
    {
        if (! $invoice->status->canTransitionTo($to)) {
            throw new DomainRuleViolation("Cannot change an invoice from {$invoice->status->value} to {$to->value}.", 'invalid_transition');
        }
    }
}
