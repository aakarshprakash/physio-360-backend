<?php

namespace App\Services\Hr;

use App\Enums\AttendanceStatus;
use App\Enums\PayrollStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\AttendanceRecord;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monthly payroll. A run computes one payslip per active staff member with a
 * salary structure:
 *
 *   loss-of-pay days = unpaid approved leave + days marked absent (half day = 0.5)
 *   paid days        = working days − loss-of-pay days
 *   earnings         = basic and each allowance × paid days / working days
 *   deductions       = fixed amounts from the structure (plus manual lines)
 *
 * Drafts can be adjusted line by line; finalizing freezes every payslip
 * (model guard + DB trigger). Money is integer minor units throughout.
 */
class PayrollService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly WorkCalendar $calendar,
        private readonly LeaveCalendar $leave,
    ) {}

    public function createRun(string $period, int $actorId): PayrollRun
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $period);
        if (! $month) {
            throw new DomainRuleViolation('Invalid payroll period.', 'invalid_period');
        }

        return DB::transaction(function () use ($month, $period, $actorId) {
            if (PayrollRun::query()->where('period', $period)->lockForUpdate()->exists()) {
                throw new DomainRuleViolation('A payroll run already exists for this month.', 'payroll_exists');
            }

            $run = new PayrollRun([
                'period' => $period,
                'currency_code' => $this->context->require()->currency_code,
                'working_days' => $this->calendar->workingDaysBetween($month->startOfMonth(), $month->endOfMonth()->startOfDay()),
                'created_by' => $actorId,
            ]);
            $run->save();

            $this->generatePayslips($run, $month);

            return $this->refreshTotals($run);
        });
    }

    /** Rebuild every payslip of a draft run from current structures, leave and attendance. */
    public function recalculate(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $locked = $this->lockDraft($run);
            $locked->payslips()->delete();
            $this->generatePayslips($locked, CarbonImmutable::createFromFormat('!Y-m', $locked->period));

            return $this->refreshTotals($locked);
        });
    }

    /**
     * @param  list<array{name:string, amount_minor:int}>  $earnings
     * @param  list<array{name:string, amount_minor:int}>  $deductions
     */
    public function adjust(Payslip $payslip, array $earnings, array $deductions, ?string $notes): Payslip
    {
        return DB::transaction(function () use ($payslip, $earnings, $deductions, $notes) {
            $run = $this->lockDraft($payslip->run);
            $clean = fn (array $lines) => array_values(array_map(
                fn ($l) => ['name' => trim((string) $l['name']), 'amount_minor' => (int) $l['amount_minor']],
                array_filter($lines, fn ($l) => trim((string) ($l['name'] ?? '')) !== ''),
            ));

            $payslip->fill($this->totals($clean($earnings), $clean($deductions)) + ['notes' => $notes])->save();
            $this->refreshTotals($run);

            return $payslip;
        });
    }

    public function finalize(PayrollRun $run, int $actorId): PayrollRun
    {
        return DB::transaction(function () use ($run, $actorId) {
            $locked = $this->lockDraft($run);
            if (! $locked->payslips()->exists()) {
                throw new DomainRuleViolation('There are no payslips to finalize. Add salary structures first.', 'empty_payroll');
            }

            $locked->forceFill(['status' => PayrollStatus::Finalized, 'finalized_by' => $actorId, 'finalized_at' => now()])->save();

            return $locked;
        });
    }

    public function markPaid(PayrollRun $run, int $actorId): PayrollRun
    {
        return DB::transaction(function () use ($run, $actorId) {
            $locked = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== PayrollStatus::Finalized) {
                throw new DomainRuleViolation('Only a finalized payroll can be marked as paid.', 'invalid_state');
            }

            $locked->forceFill(['status' => PayrollStatus::Paid, 'paid_by' => $actorId, 'paid_at' => now()])->save();

            return $locked;
        });
    }

    public function deleteDraft(PayrollRun $run): void
    {
        DB::transaction(function () use ($run) {
            $locked = $this->lockDraft($run);
            $locked->payslips()->delete();
            $locked->delete();
        });
    }

    /** Loss-of-pay days for one person in a month (unpaid leave + absences on working days). */
    public function lossOfPayDays(int $userId, CarbonImmutable $month): float
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth()->startOfDay();
        $lop = 0.0;

        foreach ($this->leave->between($from, $to, [$userId])[$userId] ?? [] as $date => $leave) {
            if (! $leave['paid'] && $this->calendar->isWorkingDay(CarbonImmutable::parse($date))) {
                $lop += $leave['half_day'] ? 0.5 : 1.0;
            }
        }

        $absences = AttendanceRecord::query()
            ->where('user_id', $userId)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', [AttendanceStatus::Absent, AttendanceStatus::HalfDay])
            ->get(['status']);

        foreach ($absences as $record) {
            $lop += $record->status === AttendanceStatus::HalfDay ? 0.5 : 1.0;
        }

        return $lop;
    }

    private function generatePayslips(PayrollRun $run, CarbonImmutable $month): void
    {
        $working = max(0, $run->working_days);

        SalaryStructure::query()
            ->whereDate('effective_from', '<=', $month->endOfMonth()->toDateString())
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->with('user:id,name')
            ->orderBy('user_id')
            ->each(function (SalaryStructure $structure) use ($run, $month, $working) {
                $lop = min((float) $working, $this->lossOfPayDays($structure->user_id, $month));
                $paid = max(0.0, $working - $lop);
                $prorate = fn (int $amount) => $working > 0 ? (int) round($amount * $paid / $working) : $amount;

                $earnings = [['name' => 'Basic', 'amount_minor' => $prorate($structure->basic_minor)]];
                foreach ($structure->allowances ?? [] as $line) {
                    $earnings[] = ['name' => $line['name'], 'amount_minor' => $prorate((int) $line['amount_minor'])];
                }
                $deductions = array_map(fn ($l) => ['name' => $l['name'], 'amount_minor' => (int) $l['amount_minor']], $structure->deductions ?? []);

                Payslip::query()->create([
                    'payroll_run_id' => $run->id,
                    'user_id' => $structure->user_id,
                    'working_days' => $working,
                    'paid_days' => $paid,
                    'lop_days' => $lop,
                ] + $this->totals($earnings, $deductions));
            });
    }

    /** @return array{earnings: array, deductions: array, gross_minor: int, total_deductions_minor: int, net_minor: int} */
    private function totals(array $earnings, array $deductions): array
    {
        $gross = (int) array_sum(array_column($earnings, 'amount_minor'));
        $deducted = (int) array_sum(array_column($deductions, 'amount_minor'));

        return [
            'earnings' => $earnings,
            'deductions' => $deductions,
            'gross_minor' => $gross,
            'total_deductions_minor' => $deducted,
            'net_minor' => $gross - $deducted,
        ];
    }

    private function refreshTotals(PayrollRun $run): PayrollRun
    {
        $sums = Payslip::query()->where('payroll_run_id', $run->id)
            ->selectRaw('COALESCE(SUM(gross_minor),0) AS g, COALESCE(SUM(total_deductions_minor),0) AS d, COALESCE(SUM(net_minor),0) AS n')
            ->first();

        $run->forceFill([
            'total_gross_minor' => (int) $sums->g,
            'total_deductions_minor' => (int) $sums->d,
            'total_net_minor' => (int) $sums->n,
        ])->save();

        return $run;
    }

    private function lockDraft(PayrollRun $run): PayrollRun
    {
        $locked = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
        if (! $locked->isDraft()) {
            throw new DomainRuleViolation('This payroll run is finalized and can no longer change.', 'payroll_finalized');
        }

        return $locked;
    }

    /** Staff who would be included (for the "missing salary structure" hint). */
    public function staffWithoutStructure(): array
    {
        return User::query()->where('is_active', true)
            ->whereNotIn('id', SalaryStructure::query()->select('user_id'))
            ->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all();
    }
}
