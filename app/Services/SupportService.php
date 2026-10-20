<?php

namespace App\Services;

use App\Enums\SupportTicketStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\PlatformAdmin;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SupportTicketNotification;
use App\Services\Sequences\SequenceAllocator;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** Clinic ⇄ SYSNAC support tickets. All writes run inside the ticket's tenant context. */
class SupportService
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly TenantContext $context,
    ) {}

    /** @param array{subject:string, category:string, priority:string, body:string} $data */
    public function open(User $user, array $data): SupportTicket
    {
        $ticket = DB::transaction(function () use ($user, $data) {
            $ticket = new SupportTicket([
                'subject' => $data['subject'],
                'category' => $data['category'],
                'priority' => $data['priority'],
                'created_by' => $user->id,
                'last_reply_at' => now(),
            ]);
            $ticket->number = $this->sequences->next('support_ticket', 'tenant', 'SUP-', 5);
            $ticket->save();

            $this->addMessage($ticket, 'clinic', $data['body'], user: $user);

            return $ticket;
        });

        $this->notifySysnac($ticket, 'opened', $data['body']);

        return $ticket;
    }

    public function replyAsClinic(SupportTicket $ticket, User $user, string $body): SupportTicket
    {
        $ticket = DB::transaction(function () use ($ticket, $user, $body) {
            $locked = $this->lockOpen($ticket);
            $this->addMessage($locked, 'clinic', $body, user: $user);
            $locked->forceFill(['status' => SupportTicketStatus::Open, 'last_reply_by' => 'clinic', 'last_reply_at' => now(), 'resolved_at' => null])->save();

            return $locked;
        });

        $this->notifySysnac($ticket, 'clinic_reply', $body);

        return $ticket;
    }

    /** Console reply; runs in the ticket's tenant context. Defaults to "waiting on clinic". */
    public function replyAsSysnac(SupportTicket $ticket, PlatformAdmin $admin, string $body, ?SupportTicketStatus $status = null): SupportTicket
    {
        $tenant = Tenant::query()->findOrFail($ticket->tenant_id);

        return $this->context->run($tenant, function () use ($ticket, $admin, $body, $status) {
            $updated = DB::transaction(function () use ($ticket, $admin, $body, $status) {
                $locked = $this->lockOpen($ticket);
                $this->addMessage($locked, 'sysnac', $body, admin: $admin);
                $next = $status ?? SupportTicketStatus::WaitingOnClinic;
                $locked->forceFill([
                    'status' => $next,
                    'last_reply_by' => 'sysnac',
                    'last_reply_at' => now(),
                    'resolved_at' => $next === SupportTicketStatus::Resolved ? now() : null,
                ])->save();

                return $locked;
            });

            if ($email = User::query()->whereKey($updated->created_by)->value('email')) {
                Notification::route('mail', $email)->notify(new SupportTicketNotification($updated, 'sysnac_reply', $body, Tenant::query()->whereKey($updated->tenant_id)->value('name')));
            }

            return $updated;
        });
    }

    public function setStatus(SupportTicket $ticket, SupportTicketStatus $status): SupportTicket
    {
        $ticket->forceFill([
            'status' => $status,
            'resolved_at' => in_array($status, [SupportTicketStatus::Resolved, SupportTicketStatus::Closed], true) ? ($ticket->resolved_at ?? now()) : null,
        ])->save();

        return $ticket;
    }

    private function addMessage(SupportTicket $ticket, string $authorType, string $body, ?User $user = null, ?PlatformAdmin $admin = null): void
    {
        // Always inside the ticket's tenant context (requests, or replyAsSysnac's run()).
        SupportTicketMessage::query()->create([
            'support_ticket_id' => $ticket->id,
            'author_type' => $authorType,
            'user_id' => $user?->id,
            'platform_admin_id' => $admin?->id,
            'author_name' => $user?->name ?? ($admin ? $admin->name.' (SYSNAC)' : 'SYSNAC'),
            'body' => $body,
            'created_at' => now(),
        ]);
    }

    private function lockOpen(SupportTicket $ticket): SupportTicket
    {
        $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
        if ($locked->status === SupportTicketStatus::Closed) {
            throw new DomainRuleViolation('This ticket is closed. Open a new ticket instead.', 'ticket_closed');
        }

        return $locked;
    }

    private function notifySysnac(SupportTicket $ticket, string $event, string $body): void
    {
        if ($to = config('physio.support.email')) {
            Notification::route('mail', $to)->notify(new SupportTicketNotification($ticket, $event, $body, $this->context->require()->name));
        }
    }
}
