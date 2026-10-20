<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\PayrollStatus;
use App\Exceptions\ImmutableRecord;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Frozen once its run is finalized (model guard + DB trigger). */
class Payslip extends Model
{
    use Auditable, BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['lop_days' => 0];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'working_days' => 'float',
            'paid_days' => 'float',
            'lop_days' => 'float',
            'earnings' => 'array',
            'deductions' => 'array',
            'gross_minor' => 'integer',
            'total_deductions_minor' => 'integer',
            'net_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Payslip $payslip) {
            if (PayrollRun::query()->whereKey($payslip->payroll_run_id)->value('status') !== PayrollStatus::Draft) {
                throw new ImmutableRecord('Payslips of a finalized payroll run are immutable.');
            }
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
