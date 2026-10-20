<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\PayrollStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayrollRunResource;
use App\Http\Resources\PayslipResource;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Services\Hr\PayrollService;
use App\Services\Pdf\PdfRenderer;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Salaries, monthly payroll runs and payslips. "payroll.manage" runs payroll;
 * every staff member can read their own finalized payslips.
 *
 * @tags Staff · Payroll
 */
class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function runs(): AnonymousResourceCollection
    {
        Gate::authorize('payroll.manage');

        return PayrollRunResource::collection(PayrollRun::query()->withCount('payslips')->latest('period')->limit(36)->get());
    }

    public function storeRun(Request $request): JsonResponse
    {
        Gate::authorize('payroll.manage');
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);

        $run = $this->payroll->createRun($data['period'], $request->user()->id);

        return (new PayrollRunResource($run->load('payslips.user')))->response()->setStatusCode(201);
    }

    public function showRun(PayrollRun $run): JsonResponse
    {
        Gate::authorize('payroll.manage');

        return (new PayrollRunResource($run->load(['payslips' => fn ($q) => $q->with('user:id,name,title,color')->orderBy('user_id')])))
            ->additional(['staff_without_salary' => $this->payroll->staffWithoutStructure()])
            ->response();
    }

    public function recalculate(PayrollRun $run): PayrollRunResource
    {
        Gate::authorize('payroll.manage');

        return new PayrollRunResource($this->payroll->recalculate($run)->load('payslips.user'));
    }

    public function finalize(Request $request, PayrollRun $run): PayrollRunResource
    {
        Gate::authorize('payroll.manage');

        return new PayrollRunResource($this->payroll->finalize($run, $request->user()->id)->load('payslips.user'));
    }

    public function markPaid(Request $request, PayrollRun $run): PayrollRunResource
    {
        Gate::authorize('payroll.manage');

        return new PayrollRunResource($this->payroll->markPaid($run, $request->user()->id)->load('payslips.user'));
    }

    public function destroyRun(PayrollRun $run): Response
    {
        Gate::authorize('payroll.manage');
        $this->payroll->deleteDraft($run);

        return response()->noContent();
    }

    /** Adjust a draft payslip (bonus, incentive, advance recovery...). */
    public function updatePayslip(Request $request, Payslip $payslip): PayslipResource
    {
        Gate::authorize('payroll.manage');
        $data = $request->validate([
            'earnings' => ['required', 'array', 'max:20'],
            'earnings.*.name' => ['required', 'string', 'max:64'],
            'earnings.*.amount_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'deductions' => ['present', 'array', 'max:20'],
            'deductions.*.name' => ['required', 'string', 'max:64'],
            'deductions.*.amount_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return new PayslipResource($this->payroll->adjust($payslip, $data['earnings'], $data['deductions'], $data['notes'] ?? null)->load(['user', 'run']));
    }

    /** The signed-in user's payslips from finalized runs. */
    public function myPayslips(Request $request): AnonymousResourceCollection
    {
        return PayslipResource::collection(
            Payslip::query()->where('user_id', $request->user()->id)
                ->whereHas('run', fn ($q) => $q->where('status', '!=', PayrollStatus::Draft))
                ->with(['run', 'user:id,name,title,color'])
                ->orderByDesc(PayrollRun::query()->select('period')->whereColumn('payroll_runs.id', 'payslips.payroll_run_id'))
                ->limit(36)->get()
        );
    }

    public function payslipPdf(Request $request, Payslip $payslip, PdfRenderer $pdf): Response
    {
        $payslip->load(['run', 'user', 'tenant']);
        $own = $payslip->user_id === $request->user()->id && $payslip->run->status !== PayrollStatus::Draft;
        abort_unless($own || $request->user()->can('payroll.manage'), 403);

        $tenant = $payslip->tenant;
        $locale = PdfRenderer::locale($request->input('lang'));
        $structure = SalaryStructure::query()->where('user_id', $payslip->user_id)->first();
        $numberLocale = $locale === 'ar' ? 'ar' : ($tenant->country_code === 'IN' ? 'en_IN' : 'en');

        $content = $pdf->render('pdf.payslip', [
            'title' => 'Payslip '.$payslip->run->period.' - '.$payslip->user->name,
            'payslip' => $payslip,
            'structure' => $structure,
            'tenant' => $tenant,
            'branch' => null,
            'locale' => $locale,
            'money' => fn (int $minor) => Money::format($minor, $payslip->run->currency_code, $numberLocale),
            'generatedAt' => now($tenant->timezone)->format('d M Y H:i'),
        ], $locale);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="payslip-'.$payslip->run->period.'-'.$payslip->user_id.'.pdf"',
        ]);
    }

    // ---- Salary structures ------------------------------------------------

    public function salaries(): JsonResponse
    {
        Gate::authorize('payroll.manage');
        $structures = SalaryStructure::query()->get()->keyBy('user_id');

        return response()->json(['data' => User::query()->where('is_active', true)->orderBy('name')->get()->map(function (User $u) use ($structures) {
            $s = $structures[$u->id] ?? null;

            return [
                'user' => ['id' => $u->id, 'name' => $u->name, 'title' => $u->title, 'color' => $u->color],
                'salary' => $s ? [
                    'basic_minor' => $s->basic_minor,
                    'allowances' => $s->allowances ?? [],
                    'deductions' => $s->deductions ?? [],
                    'effective_from' => $s->effective_from->toDateString(),
                    'bank_account' => $s->bank_account,
                    'tax_id' => $s->tax_id,
                    'monthly_gross_minor' => $s->monthlyGrossMinor(),
                    'monthly_net_minor' => $s->monthlyGrossMinor() - $s->monthlyDeductionsMinor(),
                ] : null,
            ];
        })]);
    }

    public function updateSalary(Request $request, User $user): JsonResponse
    {
        Gate::authorize('payroll.manage');
        $data = $request->validate([
            'basic_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'allowances' => ['present', 'array', 'max:20'],
            'allowances.*.name' => ['required', 'string', 'max:64'],
            'allowances.*.amount_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'deductions' => ['present', 'array', 'max:20'],
            'deductions.*.name' => ['required', 'string', 'max:64'],
            'deductions.*.amount_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'effective_from' => ['required', 'date'],
            'bank_account' => ['nullable', 'string', 'max:64'],
            'tax_id' => ['nullable', 'string', 'max:32'],
        ]);

        $structure = SalaryStructure::query()->firstOrNew(['user_id' => $user->id]);
        $structure->fill($data)->save();

        return response()->json(['data' => ['user_id' => $user->id, 'monthly_gross_minor' => $structure->monthlyGrossMinor()]]);
    }
}
