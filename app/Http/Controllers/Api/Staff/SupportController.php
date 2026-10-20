<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\SupportTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportTicketResource;
use App\Models\SupportTicket;
use App\Services\SupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Help & Support: tickets to the SYSNAC support desk. Any staff member can
 * raise a ticket and follow their own; "settings.manage" sees the clinic's.
 *
 * @tags Staff · Support
 */
class SupportController extends Controller
{
    public function __construct(private readonly SupportService $support) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', Rule::in(['open', 'closed', 'all'])]]);
        $status = $request->input('status', 'open');

        return SupportTicketResource::collection(
            $this->visible($request)
                ->when($status === 'open', fn ($q) => $q->whereIn('status', SupportTicketStatus::openValues()))
                ->when($status === 'closed', fn ($q) => $q->whereNotIn('status', SupportTicketStatus::openValues()))
                ->with('creator:id,name')
                ->withCount('messages')
                ->latest('last_reply_at')
                ->paginate(20)
        )->additional(['contact' => [
            'email' => config('physio.support.email'),
            'phone' => config('physio.support.phone'),
            'whatsapp' => config('physio.support.whatsapp'),
            'hours' => config('physio.support.hours'),
            'docs_url' => config('physio.support.docs_url'),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'priority' => ['required', Rule::in(SupportTicket::PRIORITIES)],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $ticket = $this->support->open($request->user(), $data);

        return (new SupportTicketResource($ticket->load(['creator:id,name', 'messages'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, SupportTicket $ticket): SupportTicketResource
    {
        $this->authorizeTicket($request, $ticket);

        return new SupportTicketResource($ticket->load(['creator:id,name', 'messages']));
    }

    public function reply(Request $request, SupportTicket $ticket): SupportTicketResource
    {
        $this->authorizeTicket($request, $ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        return new SupportTicketResource($this->support->replyAsClinic($ticket, $request->user(), $data['body'])->load(['creator:id,name', 'messages']));
    }

    /** The clinic marks its own ticket resolved/closed, or reopens it. */
    public function status(Request $request, SupportTicket $ticket): SupportTicketResource
    {
        $this->authorizeTicket($request, $ticket);
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'resolved', 'closed'])]]);

        return new SupportTicketResource($this->support->setStatus($ticket, SupportTicketStatus::from($data['status']))->load(['creator:id,name', 'messages']));
    }

    private function visible(Request $request)
    {
        $user = $request->user();

        return SupportTicket::query()->when(! $user->can('settings.manage'), fn ($q) => $q->where('created_by', $user->id));
    }

    private function authorizeTicket(Request $request, SupportTicket $ticket): void
    {
        abort_unless($ticket->created_by === $request->user()->id || $request->user()->can('settings.manage'), 403);
    }
}
