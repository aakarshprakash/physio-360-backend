<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Notifications\AppointmentNotification;
use App\Tenancy\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

class SendAppointmentConfirmation implements ShouldQueue
{
    use Queueable, TenantAwareJob;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $appointmentId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $appointment = Appointment::query()->with(['patient', 'practitioner', 'branch', 'service', 'tenant'])->find($this->appointmentId);

        if (! $appointment || ! $appointment->patient?->email) {
            return;
        }

        Notification::route('mail', $appointment->patient->email)
            ->notify(new AppointmentNotification($appointment, 'booked'));
    }
}
