<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PatientPackageResource;
use App\Http\Resources\PaymentResource;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientPackage;
use App\Models\Payment;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PackageService;
use App\Services\Payments\RazorpayGateway;
use App\Services\Pdf\PdfRenderer;
use App\Support\AmountInWords;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invoices: draft -> posted -> void | credited. Payments and refunds are
 * append-only. All amounts are integer minor units.
 *
 * @tags Staff · Billing
 */
class InvoiceController extends Controller
{
    private const RELATIONS = ['patient:id,first_name,last_name,mrn', 'items', 'payments.receiver:id,name', 'creditNotes'];

    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Invoice::class);

        $request->validate([
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'payment_status' => ['nullable', 'in:unpaid,partially_paid,paid,outstanding'],
            'patient_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $balance = 'total_minor - amount_credited_minor - amount_paid_minor';

        $page = Invoice::query()
            ->visibleTo($request->user())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->input('payment_status'), fn ($q, $ps) => match ($ps) {
                'paid' => $q->where('status', 'posted')->whereRaw("{$balance} <= 0"),
                'unpaid' => $q->where('status', 'posted')->whereRaw("{$balance} > 0")->where('amount_paid_minor', 0),
                'partially_paid' => $q->where('status', 'posted')->whereRaw("{$balance} > 0")->where('amount_paid_minor', '>', 0),
                'outstanding' => $q->where('status', 'posted')->whereRaw("{$balance} > 0"),
            })
            ->when($request->filled('patient_id'), fn ($q) => $q->where('patient_id', $request->integer('patient_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('from'), fn ($q) => $q->where(fn ($w) => $w->where('issue_date', '>=', $request->input('from'))->orWhereNull('issue_date')))
            ->when($request->filled('to'), fn ($q) => $q->where(fn ($w) => $w->where('issue_date', '<=', $request->input('to'))->orWhereNull('issue_date')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', '%'.$request->input('search').'%')
                ->orWhereHas('patient', fn ($p) => $p->search($request->input('search')))))
            ->with('patient:id,first_name,last_name,mrn')
            ->orderByRaw("status = 'draft' DESC")
            ->latest('issue_date')
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return InvoiceResource::collection($page);
    }

    public function store(InvoiceRequest $request): JsonResponse
    {
        Gate::authorize('create', Invoice::class);
        abort_unless($request->user()->canAccessBranch($request->integer('branch_id')), 403);

        $invoice = $this->invoices->createDraft($request->validated(), $request->user()->id);

        return (new InvoiceResource($invoice->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        Gate::authorize('view', $invoice);

        return new InvoiceResource($invoice->load(self::RELATIONS));
    }

    public function update(InvoiceRequest $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('update', $invoice);

        return new InvoiceResource($this->invoices->updateDraft($invoice, $request->validated())->load(self::RELATIONS));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        Gate::authorize('update', $invoice);
        $this->invoices->deleteDraft($invoice);

        return response()->json(null, 204);
    }

    /** Post a draft: assigns the next gapless number for the branch. */
    public function post(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('update', $invoice);
        $issueDate = $request->validate(['issue_date' => ['nullable', 'date_format:Y-m-d']])['issue_date'] ?? null;

        return new InvoiceResource($this->invoices->post($invoice, $request->user()->id, $issueDate)->load(self::RELATIONS));
    }

    public function void(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('reverse', $invoice);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];

        return new InvoiceResource($this->invoices->void($invoice, $reason, $request->user()->id)->load(self::RELATIONS));
    }

    public function creditNote(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('reverse', $invoice);
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->invoices->issueCreditNote($invoice, $data['amount_minor'], $data['reason'], $request->user()->id);

        return new InvoiceResource($invoice->refresh()->load(self::RELATIONS));
    }

    public function recordPayment(Request $request, Invoice $invoice): JsonResponse
    {
        Gate::authorize('update', $invoice);
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(PaymentMethod::class)->except([PaymentMethod::Online])],
            'reference' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:500'],
            'received_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $payment = $this->invoices->recordPayment($invoice, $data['amount_minor'], PaymentMethod::from($data['method']), [
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'received_at' => $data['received_at'] ?? now(),
            'received_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => new PaymentResource($payment),
            'invoice' => new InvoiceResource($invoice->refresh()->load(self::RELATIONS)),
        ], 201);
    }

    public function refund(Request $request, Invoice $invoice): JsonResponse
    {
        Gate::authorize('reverse', $invoice);
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $payment = $this->invoices->refund($invoice, $data['amount_minor'], PaymentMethod::from($data['method']), $data['reason'], $request->user()->id);

        return response()->json([
            'data' => new PaymentResource($payment),
            'invoice' => new InvoiceResource($invoice->refresh()->load(self::RELATIONS)),
        ], 201);
    }

    public function updateClaim(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('update', $invoice);
        $data = $request->validate([
            'claim_status' => ['required', 'in:submitted,approved,rejected,settled'],
            'claim_reference' => ['nullable', 'string', 'max:64'],
        ]);

        return new InvoiceResource($this->invoices->updateClaim($invoice, $data['claim_status'], $data['claim_reference'] ?? null)->load(self::RELATIONS));
    }

    /** Create a Razorpay payment link for the outstanding balance. */
    public function paymentLink(Request $request, Invoice $invoice, RazorpayGateway $gateway): JsonResponse
    {
        Gate::authorize('update', $invoice);
        abort_unless($invoice->status === InvoiceStatus::Posted && $invoice->balance_minor > 0, 422, 'Only posted invoices with a balance can be paid online.');

        $link = $gateway->createPaymentLink($request->user()->tenant, $invoice, $invoice->balance_minor);

        return response()->json(['data' => ['url' => $link->short_url, 'expires_at' => $link->expires_at?->toIso8601ZuluString()]], 201);
    }

    public function pdf(Request $request, Invoice $invoice, PdfRenderer $pdf): Response
    {
        Gate::authorize('view', $invoice);

        return self::renderPdf($invoice, $pdf, $request->input('lang', 'en'));
    }

    public static function renderPdf(Invoice $invoice, PdfRenderer $pdf, string $locale): Response
    {
        $invoice->load(['patient', 'items.service', 'items.appointment.practitioner', 'payments', 'tenant', 'insurancePolicy.payer']);
        $tenant = $invoice->tenant;
        $branch = Branch::query()->find($invoice->branch_id);
        $locale = PdfRenderer::locale($locale);
        $numberLocale = $locale === 'ar' ? 'ar' : ($tenant->country_code === 'IN' ? 'en_IN' : 'en');
        $timezone = $branch?->effectiveTimezone() ?? $tenant->timezone;
        $appointments = $invoice->items->pluck('appointment')->filter();

        // India: intra-state GST is shown as equal CGST + SGST halves.
        $taxLines = [];
        if ($invoice->tax_minor > 0) {
            if ($tenant->country_code === 'IN') {
                $half = intdiv($invoice->tax_minor, 2);
                $taxLines = [['CGST', $half], ['SGST', $invoice->tax_minor - $half]];
            } else {
                $taxLines = [[__('pdf.tax', [], $locale), $invoice->tax_minor]];
            }
        }

        $balance = max(0, $invoice->balance_minor);
        $upiId = $tenant->setting('invoice.upi_id');
        $upi = $upiId && $invoice->currency_code === 'INR' && $balance > 0 && $invoice->status->value === 'posted'
            ? 'upi://pay?'.http_build_query(['pa' => $upiId, 'pn' => $tenant->name, 'am' => Money::toMajorString($balance, 'INR'), 'cu' => 'INR', 'tn' => 'Invoice '.$invoice->number], '', '&', PHP_QUERY_RFC3986)
            : null;

        $content = $pdf->render('pdf.invoice', [
            'title' => 'Invoice '.($invoice->number ?? 'draft'),
            'invoice' => $invoice,
            'tenant' => $tenant,
            'branch' => $branch,
            'locale' => $locale,
            'timezone' => $timezone,
            'money' => fn (int $minor) => Money::format($minor, $invoice->currency_code, $numberLocale),
            'words' => AmountInWords::format($invoice->total_minor, $invoice->currency_code),
            'practitioners' => $appointments->pluck('practitioner.name')->filter()->unique()->values(),
            'visitDates' => $appointments->sortBy('starts_at')->map(fn ($a) => $a->starts_at->timezone($timezone)->format('d M'))->unique()->values(),
            'taxLines' => $taxLines,
            'upi' => $upi,
            'bank' => $tenant->setting('invoice.bank_details'),
            'terms' => $tenant->setting('invoice.terms'),
            'watermark' => match (true) {
                $invoice->status->value === 'void' => 'VOID',
                $invoice->status->value === 'draft' => 'DRAFT',
                $invoice->payment_status === 'paid' => 'PAID',
                default => null,
            },
            'footer' => $tenant->name.' · '.($invoice->number ?? 'Draft').'||{PAGENO} / {nbpg}',
            'generatedAt' => now($tenant->timezone)->format('d M Y H:i'),
        ], $locale);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.($invoice->number ?? 'draft-'.$invoice->id).'.pdf"',
        ]);
    }

    /** Payments ledger (receipts and refunds). */
    public function payments(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Invoice::class);
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'method' => ['nullable', Rule::enum(PaymentMethod::class)]]);

        $tz = $request->user()->tenant->timezone;
        $branchIds = $request->user()->accessibleBranchIds();

        return PaymentResource::collection(
            Payment::query()
                ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
                ->when($request->filled('from'), fn ($q) => $q->where('received_at', '>=', now()->parse($request->input('from'), $tz)->utc()))
                ->when($request->filled('to'), fn ($q) => $q->where('received_at', '<', now()->parse($request->input('to'), $tz)->addDay()->utc()))
                ->when($request->filled('method'), fn ($q) => $q->where('method', $request->input('method')))
                ->with(['invoice:id,number', 'patient:id,first_name,last_name', 'receiver:id,name'])
                ->latest('received_at')
                ->paginate(30)
        );
    }

    /** Completed appointments not yet on any non-void invoice. */
    public function uninvoiced(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Invoice::class);

        $appointments = Appointment::query()
            ->visibleTo($request->user())
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subDays(60))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->whereColumn('invoice_items.appointment_id', 'appointments.id')
                ->where('invoices.status', '!=', 'void'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('package_ledger_entries')
                ->whereColumn('package_ledger_entries.appointment_id', 'appointments.id'))
            ->with(['patient:id,first_name,last_name,mrn', 'practitioner:id,name,color', 'service:id,name,color,price_minor'])
            ->latest('starts_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $appointments->map(fn (Appointment $a) => [
            'id' => $a->id,
            'local_start' => $a->local_starts_at->format('Y-m-d\TH:i'),
            'patient' => ['id' => $a->patient->id, 'full_name' => $a->patient->full_name, 'mrn' => $a->patient->mrn],
            'practitioner' => ['id' => $a->practitioner->id, 'name' => $a->practitioner->name],
            'service' => $a->service ? ['id' => $a->service->id, 'name' => $a->service->name, 'price_minor' => $a->service->price_minor] : null,
        ])]);
    }

    public function patientPackages(Patient $patient): AnonymousResourceCollection
    {
        Gate::authorize('viewBilling', $patient);

        return PatientPackageResource::collection(
            PatientPackage::query()->where('patient_id', $patient->id)->with('ledger')->latest('purchased_on')->get()
        );
    }

    public function adjustPackage(Request $request, PatientPackage $package, PackageService $packages): PatientPackageResource
    {
        abort_unless($request->user()->can('billing.void'), 403);
        $data = $request->validate([
            'sessions' => ['required', 'integer', 'between:-50,50', 'not_in:0'],
            'note' => ['required', 'string', 'max:255'],
        ]);

        $packages->adjust($package, $data['sessions'], $data['note'], $request->user()->id);

        return new PatientPackageResource($package->refresh()->load('ledger'));
    }
}
