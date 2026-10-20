<?php

namespace App\Http\Resources;

use App\Models\PayrollRun;
use Illuminate\Http\Request;

/** @mixin PayrollRun */
class PayrollRunResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period' => $this->period,
            'status' => $this->status->value,
            'currency_code' => $this->currency_code,
            'working_days' => $this->working_days,
            'total_gross_minor' => $this->total_gross_minor,
            'total_deductions_minor' => $this->total_deductions_minor,
            'total_net_minor' => $this->total_net_minor,
            'payslips_count' => $this->whenCounted('payslips'),
            'payslips' => PayslipResource::collection($this->whenLoaded('payslips')),
            'finalized_at' => $this->iso($this->finalized_at),
            'paid_at' => $this->iso($this->paid_at),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
