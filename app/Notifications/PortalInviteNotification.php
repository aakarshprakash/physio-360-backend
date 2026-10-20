<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $clinicName, public string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your {$this->clinicName} patient app access")
            ->line("{$this->clinicName} has invited you to the Physio360 patient app, where you can book appointments, follow your exercise program and view invoices.")
            ->action('Activate your account', $this->url)
            ->line('This link expires in 72 hours and can be used once.');
    }
}
