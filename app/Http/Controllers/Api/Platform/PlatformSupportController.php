<?php

namespace App\Http\Controllers\Api\Platform;

use App\Enums\SupportTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportTicketResource;
use App\Models\PlatformAdmin;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Services\SupportService;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * SYSNAC support desk: every clinic's tickets. Crosses tenants explicitly
 * (no tenant context in the console); tickets never hold clinical data.
 *
 * @tags SYSNAC console
 */
class PlatformSupportController extends Controller
{
    public function __construct(private readonly SupportService $support) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in([...array_column(SupportTicketStatus::cases(), 'value'), 'open_all'])],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $request->input('status', 'open_all');

        $page = $this->tickets()
            ->when($status === 'open_all', fn ($q) => $q->whereIn('status', SupportTicketStatus::openValues()))
            ->when($status !== 'open_all', fn ($q) => $q->where('status', $status))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->input('priority')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('subject', 'like', '%'.$request->input('search').'%')
                ->orWhere('number', $request->input('search'))))
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->orderByRaw("CASE WHEN last_reply_by = 'clinic' THEN 0 ELSE 1 END")
            ->oldest('last_reply_at')
            ->paginate(25);

        $tenants = Tenant::query()->whereIn('id', collect($page->items())->pluck('tenant_id')->unique())->pluck('name', 'id');
        $counts = $this->tickets()->whereIn('status', SupportTicketStatus::openValues())
            ->selectRaw("COUNT(*) AS open, SUM(last_reply_by = 'clinic') AS awaiting, SUM(priority IN ('urgent','high')) AS high")->first();

        return response()->json([
            'data' => collect($page->items())->map(fn (SupportTicket $t) => (new SupportTicketResource($t))->resolve($request) + [
                'clinic' => ['id' => $t->tenant_id, 'name' => $tenants[$t->tenant_id] ?? null],
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'summary' => ['open' => (int) $counts->open, 'awaiting_sysnac' => (int) $counts->awaiting, 'high_priority' => (int) $counts->high],
        ]);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $model = $this->tickets()->with($this->messages())->findOrFail($ticket);

        return response()->json(['data' => $this->payload($request, $model)]);
    }

    public function reply(Request $request, int $ticket): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'status' => ['nullable', Rule::enum(SupportTicketStatus::class)],
        ]);
        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform')->user();

        $updated = $this->support->replyAsSysnac(
            $this->tickets()->findOrFail($ticket), $admin, $data['body'],
            isset($data['status']) ? SupportTicketStatus::from($data['status']) : null,
        );

        return response()->json(['data' => $this->payload($request, $this->tickets()->with($this->messages())->findOrFail($updated->id))]);
    }

    public function status(Request $request, int $ticket): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(SupportTicketStatus::class)]]);
        $model = $this->tickets()->findOrFail($ticket);
        $model->forceFill([
            'status' => $data['status'],
            'resolved_at' => in_array($data['status'], ['resolved', 'closed'], true) ? ($model->resolved_at ?? now()) : null,
        ])->save();

        return response()->json(['data' => $this->payload($request, $model->load($this->messages()))]);
    }

    private function tickets()
    {
        return SupportTicket::query()->withoutGlobalScope(TenantScope::class);
    }

    /** Eager-load spec for the thread: the console has no tenant context, so the scope must be lifted here too. */
    private function messages(): array
    {
        return ['messages' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)];
    }

    private function payload(Request $request, SupportTicket $ticket): array
    {
        $tenant = Tenant::query()->find($ticket->tenant_id);

        return (new SupportTicketResource($ticket))->resolve($request) + [
            'clinic' => ['id' => $tenant?->id, 'name' => $tenant?->name, 'slug' => $tenant?->slug, 'plan' => $tenant?->plan],
        ];
    }
}
