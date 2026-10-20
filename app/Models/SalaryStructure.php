<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Monthly pay structure: basic + fixed allowances, fixed deductions. Amounts in minor units. */
class SalaryStructure extends Model
{
    use Auditable, BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['basic_minor' => 0];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'basic_minor' => 'integer',
            'allowances' => 'array',
            'deductions' => 'array',
            'effective_from' => 'date',
            'bank_account' => 'encrypted',
            'tax_id' => 'encrypted',
        ];
    }

    public function monthlyGrossMinor(): int
    {
        return $this->basic_minor + (int) collect($this->allowances ?? [])->sum('amount_minor');
    }

    public function monthlyDeductionsMinor(): int
    {
        return (int) collect($this->deductions ?? [])->sum('amount_minor');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
