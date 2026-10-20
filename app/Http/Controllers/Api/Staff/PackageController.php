<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\PackageStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PatientPackage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Sold session packages across the clinic: who has sessions left, what expires soon.
 *
 * @tags Staff · Packages
 */
class PackageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Invoice::class);
        $request->validate([
            'status' => ['nullable', Rule::in([...array_column(PackageStatus::cases(), 'value'), 'expiring'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $today = CarbonImmutable::now($user->tenant->timezone)->toDateString();
        $soon = CarbonImmutable::now($user->tenant->timezone)->addDays(14)->toDateString();
        $base = fn () => PatientPackage::query()->whereHas('patient', fn ($p) => $p->visibleTo($user));

        $page = $base()
            ->when($request->input('status'), fn ($q, $status) => $status === 'expiring'
                ? $q->where('status', PackageStatus::Active)->whereBetween('expires_on', [$today, $soon])
                : $q->where('status', $status))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $request->input('search')).'%')
                ->orWhereHas('patient', fn ($p) => $p->search($request->input('search')))))
            ->with('patient:id,first_name,last_name,mrn,phone')
            ->withSum('ledger as sessions_remaining', 'sessions')
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByRaw('expires_on IS NULL, expires_on')
            ->latest('purchased_on')
            ->paginate(25);

        $active = $base()->where('status', PackageStatus::Active);

        return response()->json([
            'data' => collect($page->items())->map(fn (PatientPackage $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'patient' => ['id' => $p->patient->id, 'full_name' => $p->patient->full_name, 'mrn' => $p->patient->mrn, 'phone' => $p->patient->phone],
                'invoice_id' => $p->invoice_id,
                'sessions_total' => $p->sessions_total,
                'sessions_remaining' => (int) $p->sessions_remaining,
                'purchased_on' => $p->purchased_on?->toDateString(),
                'expires_on' => $p->expires_on?->toDateString(),
                'expiring_soon' => $p->status === PackageStatus::Active && $p->expires_on && $p->expires_on->toDateString() <= $soon,
                'status' => $p->status->value,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'summary' => [
                'active' => (clone $active)->count(),
                'expiring_14d' => (clone $active)->whereBetween('expires_on', [$today, $soon])->count(),
                'sessions_remaining' => (int) (clone $active)->withSum('ledger as remaining', 'sessions')->get()->sum('remaining'),
                'sold_30d' => $base()->where('purchased_on', '>=', CarbonImmutable::now($user->tenant->timezone)->subDays(30)->toDateString())->count(),
            ],
        ]);
    }
}
