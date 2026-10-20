<?php

namespace App\Http\Resources;

use App\Models\Payslip;
use Illuminate\Http\Request;

/** @mixin Payslip */
class PayslipResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_run_id' => $this->payroll_run_id,
            'period' => $this->whenLoaded('run', fn () => $this->run->period),
            'run_status' => $this->whenLoaded('run', fn () => $this->run->status->value),
            'user' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name, 'title' => $this->user->title, 'color' => $this->user->color]),
            'working_days' => $this->working_days,
            'paid_days' => $this->paid_days,
            'lop_days' => $this->lop_days,
            /** @var list<array{name: string, amount_minor: int}> */
            'earnings' => $this->earnings,
            /** @var list<array{name: string, amount_minor: int}> */
            'deductions' => $this->deductions,
            'gross_minor' => $this->gross_minor,
            'total_deductions_minor' => $this->total_deductions_minor,
            'net_minor' => $this->net_minor,
            'notes' => $this->notes,
        ];
    }
}
