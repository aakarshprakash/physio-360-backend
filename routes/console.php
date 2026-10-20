<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Notifications\AppointmentNotification;
use App\Reports\DailySummaryService;
use App\Services\Billing\PackageService;
use App\Services\Messaging\PaymentReminderService;
use App\Services\Scheduling\BookingService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

/*
| Every tenant-owned operation runs inside TenantContext::run() so the global
| scope applies exactly as it does for HTTP requests.
*/
$eachTenant = function (callable $callback) {
    Tenant::query()->where('status', 'active')->orderBy('id')->each(
        fn (Tenant $tenant) => app(TenantContext::class)->run($tenant, $callback)
    );
};

Artisan::command('appointments:send-reminders', function () use ($eachTenant) {
    $sent = 0;
    $eachTenant(function (Tenant $tenant) use (&$sent) {
        $hours = (int) $tenant->setting('reminder_hours_before', 24);
        Appointment::query()
            ->whereIn('status', [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed])
            ->whereNull('reminder_sent_at')
            ->whereBetween('starts_at', [now(), now()->addHours($hours)])
            ->with(['patient', 'practitioner', 'branch', 'tenant'])
            ->each(function (Appointment $appointment) use (&$sent) {
                if ($email = $appointment->patient?->email) {
                    Notification::route('mail', $email)->notify(new AppointmentNotification($appointment, 'reminder'));
                    $sent++;
                }
                $appointment->forceFill(['reminder_sent_at' => now()])->saveQuietly();
            });
    });
    $this->info("Sent {$sent} reminder(s).");
})->purpose('Email patients about upcoming appointments');

Artisan::command('reports:summarize {--days=2 : Number of local days to (re)compute, ending today}', function (DailySummaryService $summaries) use ($eachTenant) {
    $days = max(1, (int) $this->option('days'));
    $eachTenant(function (Tenant $tenant) use ($summaries, $days) {
        $today = CarbonImmutable::now($tenant->timezone)->startOfDay();
        Branch::query()->each(function (Branch $branch) use ($summaries, $tenant, $today, $days) {
            for ($i = $days - 1; $i >= 0; $i--) {
                $summaries->store($tenant, $branch, $today->subDays($i));
            }
        });
    });
    $this->info('Summaries updated.');
})->purpose('Refresh daily report summaries');

Artisan::command('packages:expire', function (PackageService $packages) use ($eachTenant) {
    $total = 0;
    $eachTenant(function (Tenant $tenant) use ($packages, &$total) {
        $total += $packages->expireDue(CarbonImmutable::now($tenant->timezone)->startOfDay());
    });
    $this->info("Expired {$total} package(s).");
})->purpose('Expire session packages past their validity date');

Artisan::command('invoices:send-payment-reminders {--force : Send now instead of waiting for 10:00 clinic time}', function (PaymentReminderService $reminders) use ($eachTenant) {
    $sent = 0;
    $eachTenant(function (Tenant $tenant) use ($reminders, &$sent) {
        // Runs hourly; each clinic is reminded once a day at 10:00 local time.
        if (! $this->option('force') && (int) CarbonImmutable::now($tenant->timezone)->format('G') !== 10) {
            return;
        }
        $sent += $reminders->sendScheduled($tenant);
    });
    $this->info("Sent {$sent} payment reminder(s).");
})->purpose('Send automatic payment reminders for overdue invoices');

Artisan::command('appointments:rebase-timezones', function (BookingService $booking) use ($eachTenant) {
    $moved = 0;
    $eachTenant(function () use ($booking, &$moved) {
        Appointment::query()
            ->whereIn('status', AppointmentStatus::blockingValues())
            ->where('starts_at', '>', now())
            ->each(function (Appointment $appointment) use ($booking, &$moved) {
                $moved += $booking->rebaseFromLocalTime($appointment) ? 1 : 0;
            });
    });
    $this->info("Re-derived UTC times for {$moved} appointment(s). Run after tzdata updates.");
})->purpose('Recompute UTC instants of future appointments from their stored local time');

Artisan::command('platform:create-admin {email} {name} {--password=}', function () {
    $password = $this->option('password') ?: Str::password(16);
    $admin = PlatformAdmin::query()->firstOrNew(['email' => strtolower($this->argument('email'))]);
    $admin->name = $this->argument('name');
    $admin->password = $password;
    $admin->save();
    $this->info("Platform admin {$admin->email} ready.");
    if (! $this->option('password')) {
        $this->warn("Generated password: {$password}");
    }
})->purpose('Create or reset a SYSNAC console administrator');

Schedule::command('appointments:send-reminders')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('reports:summarize')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('packages:expire')->dailyAt('00:30')->onOneServer();
Schedule::command('invoices:send-payment-reminders')->hourlyAt(5)->withoutOverlapping()->onOneServer();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
