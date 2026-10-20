<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\MessageChannel;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Invoice;
use App\Services\Messaging\Messenger;
use App\Services\Messaging\PaymentReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Outstanding invoices and payment reminders (manual and automatic).
 *
 * @tags Staff · Messaging
 */
class PaymentReminderController extends Controller
{
    public function __construct(
        private readonly PaymentReminderService $reminders,
        private readonly Messenger $messenger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('messages.send');
        Gate::authorize('viewAny', Invoice::class);
        $request->validate([
            'overdue' => ['nullable', Rule::in(['all', 'due', '7', '30', '60'])],
            'search' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $tenant = $user->tenant;
        $today = CarbonImmutable::now($tenant->timezone)->startOfDay();
        $overdue = $request->input('overdue', 'all');

        $scope = fn ($q) => $q->visibleTo($user)
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')));

        $page = $scope($this->reminders->outstanding())
            ->when($overdue === 'due', fn ($q) => $q->where('due_date', '<', $today->toDateString()))
            ->when(is_numeric($overdue), fn ($q) => $q->where('due_date', '<=', $today->subDays((int) $overdue)->toDateString()))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $request->input('search')).'%')
                ->orWhereHas('patient', fn ($p) => $p->search($request->input('search')))))
            ->with('patient:id,first_name,last_name,mrn,phone,email,preferred_language')
            ->orderByRaw('due_date IS NULL, due_date')
            ->paginate(25);

        $totals = $scope($this->reminders->owing())->toBase()->selectRaw(
            'COUNT(*) AS c, COALESCE(SUM(total_minor - amount_paid_minor - amount_credited_minor), 0) AS due, SUM(due_date < ?) AS overdue',
            [$today->toDateString()],
        )->first();

        return response()->json([
            'data' => collect($page->items())->map(fn (Invoice $i) => [
                'id' => $i->id,
                'number' => $i->number,
                'patient' => [
                    'id' => $i->patient->id, 'full_name' => $i->patient->full_name, 'mrn' => $i->patient->mrn,
                    'has_phone' => (bool) $i->patient->phone, 'has_email' => (bool) $i->patient->email,
                ],
                'issue_date' => $i->issue_date?->toDateString(),
                'due_date' => $i->due_date?->toDateString(),
                'days_overdue' => $i->daysOverdue($tenant->timezone),
                'total_minor' => $i->total_minor,
                'balance_minor' => $i->balance_minor,
                'reminder_count' => (int) $i->reminder_count,
                'last_reminded_at' => $i->last_reminded_at ? CarbonImmutable::parse($i->last_reminded_at)->utc()->toIso8601ZuluString() : null,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'summary' => [
                'invoices' => (int) $totals->c,
                'amount_due_minor' => (int) $totals->due,
                'overdue' => (int) $totals->overdue,
            ],
            'automation' => [
                'enabled' => (bool) $tenant->setting('payment_reminders.enabled', false),
                'channel' => $tenant->setting('payment_reminders.channel', 'email'),
                'days_after_due' => array_map('intval', (array) $tenant->setting('payment_reminders.days_after_due', [1, 7, 14])),
                'include_payment_link' => (bool) $tenant->setting('payment_reminders.include_payment_link', false),
            ],
            'channels' => collect(MessageChannel::cases())->mapWithKeys(fn ($c) => [$c->value => $this->messenger->canDeliver($tenant, $c)]),
        ]);
    }

    /** Send reminders for selected invoices; invoices that cannot be reminded come back in "skipped". */
    public function send(Request $request): JsonResponse
    {
        Gate::authorize('messages.send');
        $data = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_ids.*' => ['integer', 'distinct'],
            'channel' => ['required', Rule::enum(MessageChannel::class)],
            'include_payment_link' => ['sometimes', 'boolean'],
            'template_id' => ['nullable', 'integer', Rule::exists('message_templates', 'id')->where('tenant_id', $request->user()?->tenant_id)],
        ]);

        $invoices = Invoice::query()->visibleTo($request->user())->whereIn('id', $data['invoice_ids'])->with('patient')->get();
        $sent = [];
        $skipped = [];

        foreach ($invoices as $invoice) {
            try {
                $sent[] = $this->reminders->remind(
                    $invoice, MessageChannel::from($data['channel']), $request->user()->id,
                    (bool) ($data['include_payment_link'] ?? false), $data['template_id'] ?? null,
                );
            } catch (DomainRuleViolation $e) {
                $skipped[] = ['invoice_id' => $invoice->id, 'number' => $invoice->number, 'reason' => $e->errorCode];
            }
        }

        return response()->json([
            'data' => MessageResource::collection(collect($sent)->map->load(['patient:id,first_name,last_name,mrn', 'invoice:id,number', 'sender:id,name'])),
            'skipped' => $skipped,
        ], 201);
    }
}
