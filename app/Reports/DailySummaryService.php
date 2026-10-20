<?php

namespace App\Reports;

use App\Models\Branch;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the daily_summaries rows that reports read instead of scanning raw
 * tables. Days are clinic-local: [00:00, 24:00) in the branch timezone,
 * converted to exact UTC bounds (DST-safe, no CONVERT_TZ tables needed).
 *
 * Raw query-builder access is allowed here (and only in App\Reports) because
 * every query below filters on tenant_id explicitly.
 */
class DailySummaryService
{
    public function compute(Tenant $tenant, Branch $branch, CarbonImmutable $localDate): array
    {
        $tz = $branch->timezone ?: $tenant->timezone;
        $from = CarbonImmutable::parse($localDate->toDateString(), $tz)->utc();
        $to = CarbonImmutable::parse($localDate->toDateString(), $tz)->addDay()->utc();
        $db = DB::connection(config('database.reports_connection'));

        $scoped = fn (string $table): Builder => $db->table($table)
            ->where($table.'.tenant_id', $tenant->id)
            ->where($table.'.branch_id', $branch->id);

        $appointments = $scoped('appointments')
            ->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
            ->selectRaw("COUNT(*) AS total,
                SUM(status = 'completed') AS completed,
                SUM(status = 'cancelled') AS cancelled,
                SUM(status = 'no_show') AS no_show")
            ->first();

        $collected = (int) $scoped('payments')
            ->where('received_at', '>=', $from)->where('received_at', '<', $to)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'refund' THEN -amount_minor ELSE amount_minor END), 0) AS net")
            ->value('net');

        $invoiced = (int) $scoped('invoices')
            ->whereIn('status', ['posted', 'credited'])
            ->where('issue_date', $localDate->toDateString())
            ->sum('total_minor');

        $newPatients = $db->table('patients')
            ->where('tenant_id', $tenant->id)
            ->where('primary_branch_id', $branch->id)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->count();

        return [
            'invoiced_minor' => $invoiced,
            'collected_minor' => $collected,
            'appointments_total' => (int) ($appointments->total ?? 0),
            'appointments_completed' => (int) ($appointments->completed ?? 0),
            'appointments_cancelled' => (int) ($appointments->cancelled ?? 0),
            'appointments_no_show' => (int) ($appointments->no_show ?? 0),
            'new_patients' => $newPatients,
        ];
    }

    /** Upserts are written to the primary connection. */
    public function store(Tenant $tenant, Branch $branch, CarbonImmutable $localDate): array
    {
        $values = $this->compute($tenant, $branch, $localDate);

        DB::table('daily_summaries')->upsert(
            [['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'date' => $localDate->toDateString(), 'computed_at' => now()] + $values],
            ['tenant_id', 'branch_id', 'date'],
            array_merge(array_keys($values), ['computed_at']),
        );

        return $values;
    }
}
