<?php

use App\Enums\Role;
use App\Exceptions\ImmutableRecord;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\PackagePlan;
use App\Models\Payment;
use App\Models\Service;
use App\Services\Billing\PackageService;
use App\Services\Scheduling\BookingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->branch] = clinic();
    $this->patient = patient($this->tenant);
    $this->service = service($this->tenant, 'FOLLOW'); // ₹800.00
});

function draft(array $items, array $extra = []): TestResponse
{
    $t = test();

    return actingAsStaff($t->owner)->postJson('/api/invoices', array_merge([
        'patient_id' => $t->patient->id,
        'branch_id' => $t->branch->id,
        'items' => $items,
    ], $extra));
}

it('computes line and invoice totals in integer minor units', function () {
    draft([
        ['service_id' => $this->service->id, 'quantity' => 2, 'discount_minor' => 10000],          // 1600 - 100 = 1500, no tax
        ['description' => 'Knee brace', 'unit_price_minor' => 125050, 'tax_rate_bp' => 1200],      // 1250.50 + 12% = 150.06 (rounded)
    ])->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.number', null)
        ->assertJsonPath('data.subtotal_minor', 160000 + 125050)
        ->assertJsonPath('data.discount_minor', 10000)
        ->assertJsonPath('data.tax_minor', 15006)
        ->assertJsonPath('data.total_minor', 150000 + 125050 + 15006);
});

it('assigns gapless per-branch numbers only when posting', function () {
    $a = draft([['service_id' => $this->service->id]])->json('data.id');
    $b = draft([['service_id' => $this->service->id]])->json('data.id');
    $c = draft([['service_id' => $this->service->id]])->json('data.id');

    // Deleting a draft must not burn a number.
    actingAsStaff($this->owner)->deleteJson("/api/invoices/{$b}")->assertNoContent();

    actingAsStaff($this->owner)->postJson("/api/invoices/{$c}/post")->assertOk()->assertJsonPath('data.number', 'INV-KOC-000001');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$a}/post")->assertOk()->assertJsonPath('data.number', 'INV-KOC-000002');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$a}/post")->assertUnprocessable()->assertJsonPath('code', 'not_draft');
});

it('numbers each branch independently', function () {
    $other = inTenant($this->tenant, fn () => Branch::query()->create(['name' => 'Second', 'code' => 'TVM']));

    $x = draft([['service_id' => $this->service->id]])->json('data.id');
    $y = draft([['service_id' => $this->service->id]], ['branch_id' => $other->id])->json('data.id');

    actingAsStaff($this->owner)->postJson("/api/invoices/{$x}/post")->assertJsonPath('data.number', 'INV-KOC-000001');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$y}/post")->assertJsonPath('data.number', 'INV-TVM-000001');
});

it('freezes posted invoices in the API, the model and the database', function () {
    $id = draft([['service_id' => $this->service->id]])->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/post")->assertOk();

    actingAsStaff($this->owner)->putJson("/api/invoices/{$id}", ['items' => [['description' => 'x', 'unit_price_minor' => 1]]])
        ->assertUnprocessable()->assertJsonPath('code', 'not_draft');
    actingAsStaff($this->owner)->deleteJson("/api/invoices/{$id}")->assertUnprocessable();

    // Even raw SQL cannot alter the money or delete it.
    expect(fn () => DB::table('invoices')->where('id', $id)->update(['total_minor' => 1]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('invoices')->where('id', $id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('invoice_items')->where('invoice_id', $id)->update(['total_minor' => 1]))->toThrow(QueryException::class);
});

it('tracks partial and full payment and blocks overpayment', function () {
    $id = draft([['service_id' => $this->service->id]])->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/post");

    $pay = fn (int $amount) => actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/payments", ['amount_minor' => $amount, 'method' => 'upi', 'reference' => 'UTR123']);

    $pay(30000)->assertCreated()->assertJsonPath('invoice.payment_status', 'partially_paid')->assertJsonPath('invoice.balance_minor', 50000);
    $pay(60000)->assertUnprocessable()->assertJsonPath('code', 'overpayment');
    $pay(50000)->assertCreated()->assertJsonPath('invoice.payment_status', 'paid')->assertJsonPath('invoice.balance_minor', 0);
});

it('does not accept payments on drafts', function () {
    $id = draft([['service_id' => $this->service->id]])->json('data.id');

    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/payments", ['amount_minor' => 100, 'method' => 'cash'])
        ->assertUnprocessable()->assertJsonPath('code', 'invalid_state');
});

it('only voids invoices with no net payments', function () {
    $id = draft([['service_id' => $this->service->id]])->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/post");
    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/payments", ['amount_minor' => 80000, 'method' => 'cash']);

    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/void", ['reason' => 'Duplicate'])->assertUnprocessable()->assertJsonPath('code', 'has_payments');

    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/refunds", ['amount_minor' => 80000, 'method' => 'cash', 'reason' => 'Duplicate'])
        ->assertCreated()->assertJsonPath('invoice.amount_paid_minor', 0);

    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/void", ['reason' => 'Duplicate'])->assertOk()
        ->assertJsonPath('data.status', 'void')
        ->assertJsonPath('data.number', 'INV-KOC-000001'); // keeps its number: the sequence stays gapless
});

it('moves to credited when credit notes reach the total', function () {
    $id = draft([['service_id' => $this->service->id]])->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/post");

    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/credit-notes", ['amount_minor' => 30000, 'reason' => 'Goodwill'])
        ->assertOk()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.balance_minor', 50000)
        ->assertJsonPath('data.credit_notes.0.number', 'CN-KOC-000001');

    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/credit-notes", ['amount_minor' => 60000, 'reason' => 'Too much'])
        ->assertUnprocessable()->assertJsonPath('code', 'invalid_amount');

    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/credit-notes", ['amount_minor' => 50000, 'reason' => 'Rest'])
        ->assertOk()->assertJsonPath('data.status', 'credited');
});

it('keeps payments append-only', function () {
    $id = draft([['service_id' => $this->service->id]])->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/post");
    $paymentId = actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/payments", ['amount_minor' => 1000, 'method' => 'cash'])->json('data.id');

    expect(fn () => DB::table('payments')->where('id', $paymentId)->update(['amount_minor' => 1]))->toThrow(QueryException::class)
        ->and(fn () => inTenant($this->tenant, fn () => Payment::query()->find($paymentId)->delete()))->toThrow(ImmutableRecord::class);
});

it('uses three-decimal currencies end to end', function () {
    ['tenant' => $kw, 'owner' => $owner, 'branch' => $branch] = clinic(['currency_code' => 'KWD', 'timezone' => 'Asia/Kuwait', 'branch_code' => 'SAL']);
    $p = patient($kw);

    $invoice = actingAsStaff($owner)->postJson('/api/invoices', [
        'patient_id' => $p->id, 'branch_id' => $branch->id,
        'items' => [['description' => 'Assessment', 'unit_price_minor' => 32500], ['description' => 'Taping', 'unit_price_minor' => 4750, 'tax_rate_bp' => 500]],
    ])->assertCreated()->assertJsonPath('data.currency', 'KWD')->json('data');

    expect($invoice['tax_minor'])->toBe(238)                 // 5% of 4.750 = 0.2375 -> 0.238
        ->and($invoice['total_minor'])->toBe(32500 + 4750 + 238);

    actingAsStaff($owner)->getJson('/api/auth/me')->assertJsonPath('data.tenant.currency.exponent', 3);
});

it('creates a session package on posting and consumes it on completion', function () {
    $therapist = staff($this->tenant, Role::Therapist);
    $plan = inTenant($this->tenant, fn () => PackagePlan::query()->where('sessions_count', 10)->firstOrFail());

    $id = draft([['package_plan_id' => $plan->id]])->assertJsonPath('data.total_minor', $plan->price_minor)->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/post")->assertOk();

    $packages = actingAsStaff($this->owner)->getJson("/api/patients/{$this->patient->id}/packages")->assertOk()
        ->assertJsonPath('data.0.sessions_total', 10)->assertJsonPath('data.0.sessions_remaining', 10);

    // Complete a covered appointment -> one debit; repeating completion logic is idempotent.
    $appointment = inTenant($this->tenant, fn () => app(BookingService::class)->book(bookingRequest($this->patient, $therapist, nextWorkday(), ['serviceId' => $this->service->id])));
    foreach (['confirmed', 'checked_in', 'completed'] as $status) {
        actingAsStaff($this->owner)->postJson("/api/appointments/{$appointment->id}/status", ['status' => $status])->assertOk();
    }
    inTenant($this->tenant, fn () => app(PackageService::class)->consumeForAppointment($appointment->fresh()));

    actingAsStaff($this->owner)->getJson("/api/patients/{$this->patient->id}/packages")
        ->assertJsonPath('data.0.sessions_remaining', 9)
        ->assertJsonCount(2, 'data.0.ledger');

    // A package with used sessions cannot be voided away.
    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/void", ['reason' => 'x'])->assertUnprocessable();
});

it('cancels an unused package when its invoice is voided', function () {
    $plan = inTenant($this->tenant, fn () => PackagePlan::query()->firstOrFail());
    $id = draft([['package_plan_id' => $plan->id]])->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/post");
    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/void", ['reason' => 'Changed mind'])->assertOk();

    actingAsStaff($this->owner)->getJson("/api/patients/{$this->patient->id}/packages")
        ->assertJsonPath('data.0.status', 'cancelled')->assertJsonPath('data.0.sessions_remaining', 0);
});

it('lets only billing.void roles reverse money', function () {
    $receptionist = staff($this->tenant, Role::Receptionist);
    $accountant = staff($this->tenant, Role::Accountant);
    $therapist = staff($this->tenant, Role::Therapist);
    $id = draft([['service_id' => $this->service->id]])->json('data.id');

    actingAsStaff($therapist)->postJson("/api/invoices/{$id}/post")->assertForbidden();
    actingAsStaff($receptionist)->postJson("/api/invoices/{$id}/post")->assertOk();
    actingAsStaff($receptionist)->postJson("/api/invoices/{$id}/void", ['reason' => 'x'])->assertForbidden();
    actingAsStaff($accountant)->postJson("/api/invoices/{$id}/void", ['reason' => 'x'])->assertOk();
});

it('renders invoice PDFs in English and Arabic', function () {
    $id = draft([['service_id' => $this->service->id]])->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/post");

    $en = actingAsStaff($this->owner)->get("/api/invoices/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $ar = actingAsStaff($this->owner)->get("/api/invoices/{$id}/pdf?lang=ar")->assertOk();

    expect($en->getContent())->toStartWith('%PDF')->and($ar->getContent())->toStartWith('%PDF');
});

it('creates Razorpay payment links and records webhook payments exactly once', function () {
    $this->tenant->forceFill(['gateway_credentials' => ['razorpay' => ['key_id' => 'rzp_test_x', 'key_secret' => 'sk', 'webhook_secret' => 'whsec']]])->save();
    Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'plink_123', 'short_url' => 'https://rzp.io/i/abc', 'status' => 'created'])]);

    $id = draft([['service_id' => $this->service->id]])->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/post");
    actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/payment-link")->assertCreated()->assertJsonPath('data.url', 'https://rzp.io/i/abc');

    Http::assertSent(fn ($request) => $request['amount'] === 80000 && $request['currency'] === 'INR');

    $payload = json_encode(['event' => 'payment_link.paid', 'payload' => [
        'payment_link' => ['entity' => ['id' => 'plink_123', 'status' => 'paid']],
        'payment' => ['entity' => ['id' => 'pay_999', 'amount' => 80000, 'method' => 'upi', 'created_at' => time()]],
    ]]);
    $send = fn (string $signature) => $this->call('POST', "/api/webhooks/razorpay/{$this->tenant->slug}", [], [], [], [
        'HTTP_X-Razorpay-Signature' => $signature, 'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $send('forged')->assertUnauthorized();
    $send(hash_hmac('sha256', $payload, 'whsec'))->assertOk();
    $send(hash_hmac('sha256', $payload, 'whsec'))->assertOk(); // provider retry

    inTenant($this->tenant, function () use ($id) {
        expect(Payment::query()->where('gateway_payment_id', 'pay_999')->count())->toBe(1)
            ->and(Invoice::query()->find($id)->payment_status)->toBe('paid');
    });
});

it('lists uninvoiced completed appointments for the front desk', function () {
    $therapist = staff($this->tenant, Role::Therapist);
    $appointment = inTenant($this->tenant, fn () => app(BookingService::class)->book(bookingRequest($this->patient, $therapist, nextWorkday(), ['serviceId' => $this->service->id])));
    foreach (['confirmed', 'checked_in', 'completed'] as $status) {
        actingAsStaff($this->owner)->postJson("/api/appointments/{$appointment->id}/status", ['status' => $status]);
    }

    actingAsStaff($this->owner)->getJson('/api/billing/uninvoiced-appointments')->assertOk()->assertJsonPath('data.0.id', $appointment->id);
    actingAsStaff($this->owner)->postJson("/api/appointments/{$appointment->id}/invoice")->assertCreated();
    actingAsStaff($this->owner)->getJson('/api/billing/uninvoiced-appointments')->assertJsonCount(0, 'data');
});

it('filters invoices by payment status', function () {
    $paid = draft([['service_id' => $this->service->id]])->json('data.id');
    $open = draft([['service_id' => $this->service->id]])->json('data.id');
    foreach ([$paid, $open] as $id) {
        actingAsStaff($this->owner)->postJson("/api/invoices/{$id}/post");
    }
    actingAsStaff($this->owner)->postJson("/api/invoices/{$paid}/payments", ['amount_minor' => 80000, 'method' => 'card']);

    actingAsStaff($this->owner)->getJson('/api/invoices?payment_status=outstanding')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open);
    actingAsStaff($this->owner)->getJson('/api/invoices?payment_status=paid')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $paid);
});

it('validates service references belong to the clinic', function () {
    ['tenant' => $other] = clinic();
    $foreign = inTenant($other, fn () => Service::query()->firstOrFail());

    draft([['service_id' => $foreign->id]])->assertUnprocessable()->assertJsonValidationErrors('items.0.service_id');
});
