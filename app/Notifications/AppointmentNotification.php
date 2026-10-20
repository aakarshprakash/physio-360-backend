<?php

namespace App\Notifications;

use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Patient-facing appointment email. Deliberately contains no clinical details.
 */
class AppointmentNotification extends Notification
{
    public function __construct(public Appointment $appointment, public string $kind) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->appointment;
        $when = CarbonImmutable::parse($a->local_starts_at)->format('l, j F Y \a\t g:i A');
        $clinic = $a->tenant->name;

        $message = (new MailMessage)
            ->subject($this->kind === 'reminder' ? "Reminder: your appointment at {$clinic}" : "Your appointment at {$clinic} is booked")
            ->greeting('Hello '.$a->patient->first_name.',')
            ->line(($this->kind === 'reminder' ? 'This is a reminder of your appointment' : 'Your appointment has been booked').':')
            ->line("**{$when}** with {$a->practitioner->name}")
            ->line($a->type->value === 'home_visit' ? 'Home visit' : $a->branch->name.($a->branch->address ? ', '.$a->branch->address : ''));

        return $message
            ->action('View in patient app', rtrim(config('app.frontend_url'), '/').'/my/?clinic='.$a->tenant->slug)
            ->line('If you need to reschedule, please contact the clinic or use the patient app.');
    }
}
