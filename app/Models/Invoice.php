<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\InvoiceStatus;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    /** Status, number and money totals are only ever set by InvoiceService. */
    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'draft', 'payer_type' => 'self', 'subtotal_minor' => 0, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0, 'amount_paid_minor' => 0, 'amount_credited_minor' => 0];

    protected $guarded = ['id', 'tenant_id', 'number', 'status', 'subtotal_minor', 'discount_minor', 'tax_minor',
        'total_minor', 'amount_paid_minor', 'amount_credited_minor', 'posted_at', 'posted_by', 'voided_at', 'voided_by'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'amount_credited_minor' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $branchIds = $user->accessibleBranchIds();

        return $branchIds === null ? $query : $query->whereIn('branch_id', $branchIds);
    }

    /** Outstanding = total - credited - net paid. Negative means a refund is owed. */
    protected function balanceMinor(): Attribute
    {
        return Attribute::get(fn () => $this->total_minor - $this->amount_credited_minor - $this->amount_paid_minor);
    }

    protected function paymentStatus(): Attribute
    {
        return Attribute::get(function () {
            if ($this->status === InvoiceStatus::Draft || $this->status === InvoiceStatus::Void) {
                return null;
            }
            $due = $this->total_minor - $this->amount_credited_minor;
            if ($due <= 0 || $this->amount_paid_minor >= $due) {
                return 'paid';
            }

            return $this->amount_paid_minor > 0 ? 'partially_paid' : 'unpaid';
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('received_at');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /** Whole local days past the due date (0 when not yet due or no due date). */
    public function daysOverdue(string $timezone): int
    {
        if (! $this->due_date) {
            return 0;
        }

        $due = CarbonImmutable::parse($this->due_date->toDateString(), $timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return $due->lessThan($today) ? (int) $due->diffInDays($today) : 0;
    }

    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function insurancePolicy(): BelongsTo
    {
        return $this->belongsTo(PatientInsurancePolicy::class, 'insurance_policy_id');
    }
}
