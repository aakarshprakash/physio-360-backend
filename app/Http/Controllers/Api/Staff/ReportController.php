<?php

namespace App\Http\Controllers\Api\Staff;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\FeedbackResource;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Feedback;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Tenant;
use App\Reports\ReportService;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Staff · Dashboard & reports
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports, private readonly TenantContext $tenants) {}

    /** Home dashboard: KPIs (cached 60s per branch scope), today's schedule and recent trend. */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $this->tenant();
        $branchIds = $user->accessibleBranchIds();
        $branchIds = $request->filled('branch_id') && $user->canAccessBranch($request->integer('branch_id'))
            ? [$request->integer('branch_id')]
            : $branchIds;

        $cacheKey = $this->tenants->cacheKey('dashboard:'.hash('xxh128', json_encode($branchIds)));
        $kpis = Cache::remember($cacheKey, 60, fn () => $this->reports->dashboard($tenant, $branchIds));

        $now = CarbonImmutable::now($tenant->timezone);
        $upcoming = Appointment::query()
            ->visibleTo($user)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when($user->is_practitioner && ! $user->can('branches.all') && $request->boolean('mine', false), fn ($q) => $q->where('practitioner_id', $user->id))
            ->where('starts_at', '>=', $now->startOfDay()->utc())
            ->where('starts_at', '<', $now->startOfDay()->addDay()->utc())
            ->whereNotIn('status', ['cancelled'])
            ->with(['patient', 'practitioner', 'service', 'room'])
            ->orderBy('starts_at')
            ->limit(12)
            ->get();

        $payload = ['kpis' => $kpis, 'today' => AppointmentResource::collection($upcoming)];

        if ($user->can('reports.view') || $user->can('billing.view')) {
            $payload['revenue_trend'] = $this->reports->dailySeries($tenant, $now->subDays(13)->startOfDay(), $now->startOfDay(), $branchIds);
        } else {
            unset($payload['kpis']['revenue_month_minor'], $payload['kpis']['pending_payments']);
        }

        return response()->json(['data' => $payload]);
    }

    /** Analytics for a local date range (max 366 days). */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('reports.view');
        [$from, $to, $branchIds] = $this->range($request);
        $tenant = $this->tenant();

        $series = $this->reports->dailySeries($tenant, $from, $to, $branchIds);
        $totals = collect($series)->reduce(function (array $carry, array $day) {
            foreach ($day as $key => $value) {
                if ($key !== 'date') {
                    $carry[$key] = ($carry[$key] ?? 0) + $value;
                }
            }

            return $carry;
        }, []);

        $totals['no_show_rate'] = ($totals['appointments_total'] ?? 0) > 0
            ? round($totals['appointments_no_show'] / $totals['appointments_total'] * 100, 1) : 0.0;

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $totals,
            'series' => $series,
            'breakdown' => $this->reports->breakdown($tenant, $from, $to, $branchIds),
            'aging' => $this->reports->aging($tenant, $branchIds),
        ]]);
    }

    /** CSV export of daily figures. */
    public function exportSummary(Request $request, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('reports.view');
        [$from, $to, $branchIds] = $this->range($request);
        $tenant = $this->tenant();
        $series = $this->reports->dailySeries($tenant, $from, $to, $branchIds);
        $audit->record('exported', null, [], ['report' => 'daily_summary', 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

        return $this->csv("physio360-report-{$from->toDateString()}-{$to->toDateString()}.csv",
            ['Date', 'Invoiced', 'Collected', 'Appointments', 'Completed', 'Cancelled', 'No-shows', 'New patients'],
            collect($series)->map(fn ($d) => [
                $d['date'],
                Money::toMajorString($d['invoiced_minor'], $tenant->currency_code),
                Money::toMajorString($d['collected_minor'], $tenant->currency_code),
                $d['appointments_total'], $d['appointments_completed'], $d['appointments_cancelled'], $d['appointments_no_show'], $d['new_patients'],
            ]));
    }

    /** CSV export of patients (demographics only, no clinical fields). */
    public function exportPatients(Request $request, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('reports.view');
        $audit->record('exported', null, [], ['report' => 'patients']);
        $user = $request->user();

        return $this->csv('physio360-patients.csv', ['MRN', 'First name', 'Last name', 'Gender', 'Date of birth', 'Phone', 'Email', 'City', 'Status', 'Registered'],
            Patient::query()->visibleTo($user)->orderBy('mrn')->lazy(500)->map(fn (Patient $p) => [
                $p->mrn, $p->first_name, $p->last_name, $p->gender?->value, $p->date_of_birth?->toDateString(),
                $p->phone, $p->email, $p->city, $p->status, $p->created_at->toDateString(),
            ]));
    }

    public function exportInvoices(Request $request, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('reports.view');
        [$from, $to] = $this->range($request);
        $audit->record('exported', null, [], ['report' => 'invoices', 'from' => $from->toDateString(), 'to' => $to->toDateString()]);
        $currency = $this->tenant()->currency_code;

        return $this->csv("physio360-invoices-{$from->toDateString()}-{$to->toDateString()}.csv",
            ['Number', 'Issue date', 'Patient', 'MRN', 'Status', 'Subtotal', 'Discount', 'Tax', 'Total', 'Credited', 'Paid', 'Balance'],
            Invoice::query()->visibleTo($request->user())
                ->where('status', '!=', 'draft')
                ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
                ->with('patient:id,first_name,last_name,mrn')
                ->orderBy('issue_date')->orderBy('number')
                ->lazy(500)
                ->map(fn (Invoice $i) => [
                    $i->number, $i->issue_date->toDateString(), $i->patient->full_name, $i->patient->mrn, $i->status->value,
                    ...array_map(fn ($v) => Money::toMajorString($v, $currency), [
                        $i->subtotal_minor, $i->discount_minor, $i->tax_minor, $i->total_minor,
                        $i->amount_credited_minor, $i->amount_paid_minor, $i->balance_minor,
                    ]),
                ]));
    }

    public function feedback(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->canAny(['reports.view', 'clinical.view']), 403);

        return FeedbackResource::collection(
            Feedback::query()
                ->when($request->filled('practitioner_id'), fn ($q) => $q->where('practitioner_id', $request->integer('practitioner_id')))
                ->with(['patient:id,first_name,last_name', 'practitioner:id,name'])
                ->latest()
                ->paginate(20)
        );
    }

    /** Append-only audit trail for this clinic. */
    public function audit(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('audit.view');
        $request->validate([
            'auditable_type' => ['nullable', 'string', 'max:64'],
            'auditable_id' => ['nullable', 'integer'],
            'actor_id' => ['nullable', 'integer'],
            'event' => ['nullable', 'string', 'max:48'],
        ]);

        return AuditLogResource::collection(
            AuditLog::query()
                ->where('tenant_id', $request->user()->tenant_id)
                ->when($request->filled('auditable_type'), fn ($q) => $q->where('auditable_type', $request->input('auditable_type')))
                ->when($request->filled('auditable_id'), fn ($q) => $q->where('auditable_id', $request->integer('auditable_id')))
                ->when($request->filled('actor_id'), fn ($q) => $q->where('actor_type', 'user')->where('actor_id', $request->integer('actor_id')))
                ->when($request->filled('event'), fn ($q) => $q->where('event', $request->input('event')))
                ->latest('id')
                ->paginate(50)
        );
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: list<int>|null} */
    private function range(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $tz = $this->tenant()->timezone;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->input('to'), $tz) : CarbonImmutable::now($tz)->startOfDay();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->input('from'), $tz) : $to->subDays(29);
        abort_if($from->diffInDays($to) > 366, 422, 'Date range is limited to one year.');

        $user = $request->user();
        $branchIds = $user->accessibleBranchIds();
        if ($request->filled('branch_id')) {
            abort_unless($user->canAccessBranch($request->integer('branch_id')), 403);
            $branchIds = [$request->integer('branch_id')];
        }

        return [$from, $to, $branchIds];
    }

    private function tenant(): Tenant
    {
        return $this->tenants->require();
    }

    /**
     * Rows are lazy and consumed while streaming, i.e. after the tenant middleware
     * has finished, so the tenant context is re-entered inside the callback.
     */
    private function csv(string $filename, array $header, iterable $rows): StreamedResponse
    {
        $tenant = $this->tenant();

        return response()->streamDownload(function () use ($header, $rows, $tenant) {
            $this->tenants->run($tenant, function () use ($header, $rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens Arabic/Indic text correctly
                fputcsv($out, $header, escape: '');
                foreach ($rows as $row) {
                    // Neutralise spreadsheet formula injection.
                    fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $row), escape: '');
                }
                fclose($out);
            });
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
