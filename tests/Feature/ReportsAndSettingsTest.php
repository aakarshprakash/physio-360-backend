<?php

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Models\DailySummary;
use App\Services\Billing\InvoiceService;
use App\Services\Scheduling\BookingService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->branch] = clinic();
    $this->therapist = staff($this->tenant, Role::Therapist);
    $this->patient = patient($this->tenant);
    $this->service = service($this->tenant, 'FOLLOW');
});

function paidVisitToday(): void
{
    $t = test();
    inTenant($t->tenant, function () use ($t) {
        $booking = app(BookingService::class);
        $appointment = $booking->book(bookingRequest($t->patient, $t->therapist, now('Asia/Kolkata')->startOfHour()->toImmutable(), ['allowOutsideHours' => true, 'serviceId' => $t->service->id]));
        foreach ([AppointmentStatus::CheckedIn, AppointmentStatus::Completed] as $s) {
            $appointment = $booking->transition($appointment, $s);
        }

        $invoices = app(InvoiceService::class);
        $invoice = $invoices->createDraft(['patient_id' => $t->patient->id, 'branch_id' => $t->branch->id, 'items' => [['service_id' => $t->service->id, 'appointment_id' => $appointment->id]]], $t->owner->id);
        $invoices->post($invoice, $t->owner->id);
        $invoices->recordPayment($invoice->refresh(), 50000, PaymentMethod::Upi);
    });
}

it('summarises revenue, appointments and outstanding balances', function () {
    paidVisitToday();
    $today = now('Asia/Kolkata')->toDateString();

    actingAsStaff($this->owner)->getJson("/api/reports/summary?from={$today}&to={$today}")->assertOk()
        ->assertJsonPath('data.totals.invoiced_minor', 80000)
        ->assertJsonPath('data.totals.collected_minor', 50000)
        ->assertJsonPath('data.totals.appointments_completed', 1)
        ->assertJsonPath('data.breakdown.by_service.0.label', 'Follow-up Session')
        ->assertJsonPath('data.breakdown.by_practitioner.0.revenue_minor', 80000)
        ->assertJsonPath('data.aging.0.amount_minor', 30000);
});

it('shows dashboard KPIs for today', function () {
    paidVisitToday();

    actingAsStaff($this->owner)->getJson('/api/dashboard')->assertOk()
        ->assertJsonPath('data.kpis.today_appointments.value', 1)
        ->assertJsonPath('data.kpis.revenue_month_minor.value', 50000)
        ->assertJsonPath('data.kpis.pending_payments.count', 1)
        ->assertJsonPath('data.kpis.pending_payments.amount_minor', 30000)
        ->assertJsonCount(1, 'data.today')
        ->assertJsonCount(14, 'data.revenue_trend');
});

it('materialises daily summaries on schedule and lazily for past days', function () {
    paidVisitToday();

    Artisan::call('reports:summarize', ['--days' => 3]);

    inTenant($this->tenant, function () {
        expect(DailySummary::query()->count())->toBe(3)
            ->and((int) DailySummary::query()->where('date', now('Asia/Kolkata')->toDateString())->value('collected_minor'))->toBe(50000);
    });
});

it('exports CSV safely', function () {
    patient($this->tenant, ['first_name' => '=HYPERLINK("http://evil")']);

    $csv = actingAsStaff($this->owner)->get('/api/exports/patients')->assertOk()->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBFMRN,")
        ->toContain("'=HYPERLINK")
        ->and(substr_count($csv, "\n"))->toBe(3);

    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'event' => 'exported']);
});

it('manages services with integer prices and validates colours', function () {
    $id = actingAsStaff($this->owner)->postJson('/api/settings/services', [
        'name' => 'Cupping', 'duration_minutes' => 30, 'price_minor' => 70000, 'tax_rate_bp' => 1800, 'color' => '#AABBCC',
    ])->assertCreated()->json('data.id');

    actingAsStaff($this->owner)->putJson("/api/settings/services/{$id}", ['color' => 'red'])->assertJsonValidationErrors('color');
    actingAsStaff($this->owner)->putJson("/api/settings/services/{$id}", ['is_active' => false])->assertOk();
    actingAsStaff($this->owner)->getJson('/api/settings/services')->assertJsonMissing(['name' => 'Cupping']);
});

it('stores payment gateway secrets write-only and encrypted', function () {
    actingAsStaff($this->owner)->putJson('/api/settings/clinic', ['razorpay' => ['key_id' => 'rzp_live_1', 'key_secret' => 'very-secret', 'webhook_secret' => 'wh']])
        ->assertOk()->assertJsonPath('data.online_payments_enabled', true)->assertJsonMissing(['key_secret' => 'very-secret']);

    expect(DB::table('tenants')->where('id', $this->tenant->id)->value('gateway_credentials'))->not->toContain('very-secret');
});

it('adds staff with roles and branch access, protecting the last owner', function () {
    Notification::fake();

    $res = actingAsStaff($this->owner)->postJson('/api/staff', [
        'name' => 'Dr. New', 'email' => 'new@example.test', 'role' => 'therapist', 'branch_ids' => [$this->branch->id], 'is_practitioner' => true,
    ])->assertCreated()->assertJsonPath('data.roles', ['therapist']);

    $admin = staff($this->tenant, Role::Admin);
    actingAsStaff($admin)->postJson('/api/staff', [
        'name' => 'Sneaky', 'email' => 'sneaky@example.test', 'role' => 'owner', 'branch_ids' => [$this->branch->id],
    ])->assertUnprocessable()->assertJsonPath('code', 'owner_only');

    actingAsStaff($this->owner)->putJson("/api/staff/{$this->owner->id}", ['is_active' => false])->assertUnprocessable()->assertJsonPath('code', 'self_deactivation');
    actingAsStaff($this->owner)->putJson("/api/staff/{$this->owner->id}", ['role' => 'admin'])->assertUnprocessable()->assertJsonPath('code', 'last_owner');
});

it('replaces working hours and rejects overlaps', function () {
    $hours = [['branch_id' => $this->branch->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '12:00']];

    actingAsStaff($this->owner)->putJson("/api/staff/{$this->therapist->id}/working-hours", ['hours' => $hours])->assertOk()->assertJsonCount(1, 'data');
    actingAsStaff($this->owner)->putJson("/api/staff/{$this->therapist->id}/working-hours", ['hours' => [...$hours, ['branch_id' => $this->branch->id, 'weekday' => 1, 'start_time' => '11:00', 'end_time' => '13:00']]])
        ->assertUnprocessable()->assertJsonPath('code', 'invalid_hours');
});
