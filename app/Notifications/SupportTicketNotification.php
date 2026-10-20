<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Support desk emails: new tickets and clinic replies go to SYSNAC; SYSNAC
 * replies go to the clinic user who opened the ticket. Tickets must not carry
 * patient data, which the clinic portal tells users when they write one.
 */
class SupportTicketNotification extends Notification
{
    public function __construct(
        public SupportTicket $ticket,
        public string $event, // opened | clinic_reply | sysnac_reply
        public string $body,
        public string $clinicName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $t = $this->ticket;
        $subject = match ($this->event) {
            'opened' => "[{$t->number}] New ticket from {$this->clinicName}: {$t->subject}",
            'clinic_reply' => "[{$t->number}] {$this->clinicName} replied: {$t->subject}",
            default => "[{$t->number}] SYSNAC Support replied: {$t->subject}",
        };

        $portal = rtrim(config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject($subject)
            ->line("Category: {$t->category} · Priority: {$t->priority}")
            ->line($this->body)
            ->action('Open ticket', $this->event === 'sysnac_reply' ? "{$portal}/support?ticket={$t->id}" : "{$portal}/console/support?ticket={$t->id}");
    }
}
