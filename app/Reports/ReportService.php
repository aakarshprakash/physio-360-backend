<?php

namespace App\Reports;

use App\Models\Branch;
use App\Models\DailySummary;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-side analytics. Time series come from daily_summaries (lazily
 * back-filled for past days, computed live for today); breakdowns query the
 * reports connection (a read replica in production) with explicit tenant_id
 * filters, never through Eloquent global scopes.
 */
class ReportService
{
    public function __construct(private readonly DailySummaryService $summaries) {}

    /**
     * @param  list<int>|null  $branchIds  null = all branches
     * @return list<array{date: string, invoiced_minor: int, collected_minor: int, appointments_total: int, appointments_completed: int, appointments_cancelled: int, appointments_no_show: int, new_patients: int}>
     */
    public function dailySeries(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds): array
    {
        $branches = $this->branches($branchIds);
        $today = CarbonImmutable::now($tenant->timezone)->startOfDay();

        $stored = DailySummary::query()
            ->whereIn('branch_id', $branches->pluck('id'))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn (DailySummary $s) => $s->date->toDateString());

        $series = [];
        foreach (CarbonPeriod::create($from->startOfDay(), $to->startOfDay()) as $day) {
            $day = CarbonImmutable::instance($day);
            $key = $day->toDateString();
            $row = ['date' => $key] + array_fill_keys(self::METRICS, 0);

            foreach ($branches as $branch) {
                if ($day->greaterThan($today)) {
                    $values = array_fill_keys(self::METRICS, 0);
                } elseif ($day->equalTo($today)) {
                    $values = $this->summaries->compute($tenant, $branch, $day);
                } else {
                    $existing = $stored->get($key)?->firstWhere('branch_id', $branch->id);
                    $values = $existing ? $existing->only(self::METRICS) : $this->summaries->store($tenant, $branch, $day);
                }

                foreach (self::METRICS as $metric) {
                    $row[$metric] += (int) $values[$metric];
                }
            }

            $series[] = $row;
        }

        return $series;
    }

    public function dashboard(Tenant $tenant, ?array $branchIds): array
    {
        $tz = $tenant->timezone;
        $now = CarbonImmutable::now($tz);
        $today = $now->startOfDay();
        $db = $this->db();
        $branches = $this->branches($branchIds)->pluck('id')->all();

        $appointmentsOn = fn (CarbonImmutable $day) => $db->table('appointments')
            ->where('tenant_id', $tenant->id)
            ->whereIn('branch_id', $branches)
            ->where('starts_at', '>=', $day->utc())
            ->where('starts_at', '<', $day->addDay()->utc())
            ->whereNotIn('status', ['cancelled'])
            ->count();

        $activePatients = fn (CarbonImmutable $from, CarbonImmutable $to) => $db->table('appointments')
            ->where('tenant_id', $tenant->id)
            ->whereIn('branch_id', $branches)
            ->whereIn('status', ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed'])
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $to->utc())
            ->distinct()
            ->count('patient_id');

        $collected = fn (CarbonImmutable $from, CarbonImmutable $to) => (int) $db->table('payments')
            ->where('tenant_id', $tenant->id)
            ->whereIn('branch_id', $branches)
            ->where('received_at', '>=', $from->utc())
            ->where('received_at', '<', $to->utc())
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'refund' THEN -amount_minor ELSE amount_minor END), 0) AS net")
            ->value('net');

        // Month-to-date through the end of today, compared with the same number of days last month.
        $monthStart = $today->startOfMonth();
        $prevMonthStart = $monthStart->subMonth();
        $prevPeriodEnd = $prevMonthStart->addDays(min($today->day, $prevMonthStart->daysInMonth));

        $pending = $db->table('invoices')
            ->where('tenant_id', $tenant->id)
            ->whereIn('branch_id', $branches)
            ->where('status', 'posted')
            ->whereRaw('total_minor - amount_credited_minor - amount_paid_minor > 0')
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(total_minor - amount_credited_minor - amount_paid_minor), 0) AS amount')
            ->first();

        $todayCount = $appointmentsOn($today);
        $yesterdayCount = $appointmentsOn($today->subDay());
        $active = $activePatients($today->subDays(29), $today->addDay());
        $activePrev = $activePatients($today->subDays(59), $today->subDays(29));
        $revenue = $collected($monthStart, $today->addDay());
        $revenuePrev = $collected($prevMonthStart, $prevPeriodEnd);

        return [
            'today_appointments' => ['value' => $todayCount, 'change_pct' => $this->change($todayCount, $yesterdayCount)],
            'active_patients' => ['value' => $active, 'change_pct' => $this->change($active, $activePrev)],
            'revenue_month_minor' => ['value' => $revenue, 'change_pct' => $this->change($revenue, $revenuePrev)],
            'pending_payments' => ['count' => (int) $pending->count, 'amount_minor' => (int) $pending->amount],
            'patient_progress' => $this->progressByWeek($tenant, $branches, $monthStart, $now),
        ];
    }

    /**
     * Patients "improved" by week of the current month: latest pain score at
     * least 2 points (MCID for NPRS) below their first recorded score.
     */
    public function progressByWeek(Tenant $tenant, array $branchIds, CarbonImmutable $monthStart, CarbonImmutable $now): array
    {
        $entries = $this->db()->table('progress_entries')
            ->join('patients', function ($join) {
                $join->on('patients.id', '=', 'progress_entries.patient_id')
                    ->on('patients.tenant_id', '=', 'progress_entries.tenant_id');
            })
            ->where('progress_entries.tenant_id', $tenant->id)
            ->whereIn('patients.primary_branch_id', $branchIds)
            ->whereNotNull('progress_entries.pain_score')
            ->where('progress_entries.recorded_on', '>=', $monthStart->subMonths(6)->toDateString())
            ->orderBy('progress_entries.recorded_on')
            ->orderBy('progress_entries.id')
            ->get(['progress_entries.patient_id', 'progress_entries.recorded_on', 'progress_entries.pain_score'])
            ->groupBy('patient_id');

        $weeks = [];
        for ($i = 0; $i < 5; $i++) {
            $weekEnd = $monthStart->addDays(7 * ($i + 1) - 1);
            if ($monthStart->addDays(7 * $i)->greaterThan($now) || $monthStart->addDays(7 * $i)->month !== $monthStart->month) {
                break;
            }
            $cutoff = min($weekEnd, $now)->toDateString();
            $improved = 0;

            foreach ($entries as $rows) {
                $upTo = $rows->filter(fn ($r) => $r->recorded_on <= $cutoff)->values();
                if ($upTo->count() >= 2 && $upTo->last()->pain_score <= $upTo->first()->pain_score - 2) {
                    $improved++;
                }
            }

            $weeks[] = ['week' => $i + 1, 'label' => 'Week '.($i + 1), 'improved' => $improved];
        }

        $total = $entries->filter(fn ($rows) => $rows->count() >= 2)->count();

        return ['weeks' => $weeks, 'improved' => end($weeks)['improved'] ?? 0, 'tracked' => $total];
    }

    public function breakdown(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds): array
    {
        $db = $this->db();
        $branches = $this->branches($branchIds)->pluck('id')->all();

        $byService = $db->table('invoice_items')
            ->join('invoices', function ($join) {
                $join->on('invoices.id', '=', 'invoice_items.invoice_id')->on('invoices.tenant_id', '=', 'invoice_items.tenant_id');
            })
            ->leftJoin('services', 'services.id', '=', 'invoice_items.service_id')
            ->leftJoin('package_plans', 'package_plans.id', '=', 'invoice_items.package_plan_id')
            ->where('invoice_items.tenant_id', $tenant->id)
            ->whereIn('invoices.branch_id', $branches)
            ->whereIn('invoices.status', ['posted', 'credited'])
            ->whereBetween('invoices.issue_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('label')
            ->selectRaw("COALESCE(services.name, package_plans.name, 'Other') AS label, SUM(invoice_items.total_minor) AS amount_minor, SUM(invoice_items.quantity) AS quantity")
            ->orderByDesc('amount_minor')
            ->get()
            ->map(fn ($r) => ['label' => $r->label, 'amount_minor' => (int) $r->amount_minor, 'quantity' => (int) $r->quantity])
            ->all();

        $tz = $tenant->timezone;
        $fromUtc = CarbonImmutable::parse($from->toDateString(), $tz)->utc();
        $toUtc = CarbonImmutable::parse($to->toDateString(), $tz)->addDay()->utc();

        $byPractitioner = $db->table('appointments')
            ->join('users', 'users.id', '=', 'appointments.practitioner_id')
            ->where('appointments.tenant_id', $tenant->id)
            ->whereIn('appointments.branch_id', $branches)
            ->where('appointments.starts_at', '>=', $fromUtc)
            ->where('appointments.starts_at', '<', $toUtc)
            ->groupBy('users.id', 'users.name', 'users.color')
            ->selectRaw("users.id, users.name, users.color,
                COUNT(*) AS total,
                SUM(appointments.status = 'completed') AS completed,
                SUM(appointments.status = 'cancelled') AS cancelled,
                SUM(appointments.status = 'no_show') AS no_show,
                SUM(CASE WHEN appointments.status IN ('completed','checked_in','in_progress') THEN TIMESTAMPDIFF(MINUTE, appointments.starts_at, appointments.ends_at) ELSE 0 END) AS delivered_minutes")
            ->orderByDesc('completed')
            ->get();

        $revenueByPractitioner = $db->table('invoice_items')
            ->join('invoices', function ($join) {
                $join->on('invoices.id', '=', 'invoice_items.invoice_id')->on('invoices.tenant_id', '=', 'invoice_items.tenant_id');
            })
            ->join('appointments', 'appointments.id', '=', 'invoice_items.appointment_id')
            ->where('invoice_items.tenant_id', $tenant->id)
            ->whereIn('invoices.branch_id', $branches)
            ->whereIn('invoices.status', ['posted', 'credited'])
            ->whereBetween('invoices.issue_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('appointments.practitioner_id')
            ->selectRaw('appointments.practitioner_id, SUM(invoice_items.total_minor) AS amount_minor')
            ->pluck('amount_minor', 'practitioner_id');

        $ratings = $db->table('feedback')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $fromUtc)
            ->where('created_at', '<', $toUtc)
            ->groupBy('practitioner_id')
            ->selectRaw('practitioner_id, AVG(rating) AS avg_rating, COUNT(*) AS responses')
            ->get()
            ->keyBy('practitioner_id');

        return [
            'by_service' => $byService,
            'by_practitioner' => $byPractitioner->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'color' => $r->color,
                'appointments' => (int) $r->total,
                'completed' => (int) $r->completed,
                'cancelled' => (int) $r->cancelled,
                'no_show' => (int) $r->no_show,
                'delivered_minutes' => (int) $r->delivered_minutes,
                'revenue_minor' => (int) ($revenueByPractitioner[$r->id] ?? 0),
                'avg_rating' => isset($ratings[$r->id]) ? round((float) $ratings[$r->id]->avg_rating, 2) : null,
                'feedback_count' => (int) ($ratings[$r->id]->responses ?? 0),
            ])->all(),
        ];
    }

    /** Outstanding balances by age since issue date. */
    public function aging(Tenant $tenant, ?array $branchIds): array
    {
        $today = CarbonImmutable::now($tenant->timezone)->toDateString();

        $row = $this->db()->table('invoices')
            ->where('tenant_id', $tenant->id)
            ->whereIn('branch_id', $this->branches($branchIds)->pluck('id')->all())
            ->where('status', 'posted')
            ->whereRaw('total_minor - amount_credited_minor - amount_paid_minor > 0')
            ->selectRaw('
                SUM(CASE WHEN DATEDIFF(?, issue_date) <= 30 THEN total_minor - amount_credited_minor - amount_paid_minor ELSE 0 END) AS d0_30,
                SUM(CASE WHEN DATEDIFF(?, issue_date) BETWEEN 31 AND 60 THEN total_minor - amount_credited_minor - amount_paid_minor ELSE 0 END) AS d31_60,
                SUM(CASE WHEN DATEDIFF(?, issue_date) BETWEEN 61 AND 90 THEN total_minor - amount_credited_minor - amount_paid_minor ELSE 0 END) AS d61_90,
                SUM(CASE WHEN DATEDIFF(?, issue_date) > 90 THEN total_minor - amount_credited_minor - amount_paid_minor ELSE 0 END) AS d90_plus
            ', [$today, $today, $today, $today])
            ->first();

        return [
            ['bucket' => '0-30', 'amount_minor' => (int) $row->d0_30],
            ['bucket' => '31-60', 'amount_minor' => (int) $row->d31_60],
            ['bucket' => '61-90', 'amount_minor' => (int) $row->d61_90],
            ['bucket' => '90+', 'amount_minor' => (int) $row->d90_plus],
        ];
    }

    private const METRICS = ['invoiced_minor', 'collected_minor', 'appointments_total', 'appointments_completed',
        'appointments_cancelled', 'appointments_no_show', 'new_patients'];

    private function branches(?array $branchIds): Collection
    {
        return Branch::query()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))
            ->with('tenant')
            ->get();
    }

    private function change(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current === 0 ? 0.0 : null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    private function db(): Connection
    {
        return DB::connection(config('database.reports_connection'));
    }
}
