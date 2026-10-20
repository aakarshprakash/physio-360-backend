<?php

use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Jobs\SendMessage;
use App\Models\Invoice;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->branch] = clinic();
    $this->desk = staff($this->tenant, Role::Receptionist);
    $this->patient = patient($this->tenant, ['first_name' => 'Anil', 'last_name' => 'Kumar', 'phone' => '098470 12345', 'email' => 'anil@example.com']);
});

function connectTwilio(object $t): void
{
    actingAsStaff($t->owner)->putJson('/api/settings/clinic', ['twilio' => [
        'account_sid' => 'AC'.str_repeat('a1', 16), 'auth_token' => 'secret-token',
        'sms_from' => '+14155550100', 'whatsapp_from' => '+14155550101',
    ]])->assertOk()->assertJsonPath('data.messaging.whatsapp', true);
}

function postedInvoiceFor(object $t, int $daysOverdue = 3): Invoice
{
    $id = actingAsStaff($t->owner)->postJson('/api/invoices', [
        'patient_id' => $t->patient->id, 'branch_id' => $t->branch->id,
        'items' => [['service_id' => service($t->tenant)->id]],
    ])->json('data.id');
    actingAsStaff($t->owner)->postJson("/api/invoices/{$id}/post")->assertOk();

    return inTenant($t->tenant, function () use ($id, $daysOverdue) {
        $invoice = Invoice::query()->findOrFail($id);
        DB::table('invoices')->where('id', $id)->update(['due_date' => now($invoice->tenant->timezone)->subDays($daysOverdue)->toDateString()]);

        return $invoice->refresh();
    });
}

it('logs WhatsApp messages as manual with a prefilled link when no gateway is set up', function () {
    $res = actingAsStaff($this->desk)->postJson('/api/messages', [
        'channel' => 'whatsapp', 'patient_ids' => [$this->patient->id],
        'body' => 'Hello {{patient_first_name}}, greetings from {{clinic_name}}.',
    ])->assertCreated()
        ->assertJsonPath('data.0.status', 'manual')
        ->assertJsonPath('data.0.recipient', '+919847012345')
        ->assertJsonPath('data.0.body', "Hello Anil, greetings from {$this->tenant->name}.");

    expect($res->json('data.0.share_url'))->toStartWith('https://wa.me/919847012345?text=Hello%20Anil');

    // The log is evidence: content cannot be rewritten, at model or DB level.
    expect(fn () => inTenant($this->tenant, fn () => DB::table('messages')->update(['channel' => 'sms'])))->toThrow(QueryException::class);
});

it('delivers through Twilio when configured and records the provider id', function () {
    connectTwilio($this);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201)]);

    actingAsStaff($this->desk)->postJson('/api/messages', ['channel' => 'sms', 'patient_ids' => [$this->patient->id], 'body' => 'Hi'])
        ->assertCreated();

    $message = inTenant($this->tenant, fn () => Message::query()->firstOrFail());
    expect($message->status)->toBe(MessageStatus::Sent)->and($message->provider_message_id)->toBe('SM123');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/Messages.json') && $r['To'] === '+919847012345' && $r['Body'] === 'Hi');
});

it('records gateway failures for retry and marks the final attempt failed', function () {
    connectTwilio($this);
    Http::fake(['api.twilio.com/*' => Http::response(['message' => 'Invalid To number'], 400)]);
    config(['queue.default' => 'null']); // capture the job instead of running it inline

    actingAsStaff($this->desk)->postJson('/api/messages', ['channel' => 'whatsapp', 'patient_ids' => [$this->patient->id], 'body' => 'Hi'])->assertCreated();
    $id = inTenant($this->tenant, fn () => Message::query()->value('id'));

    inTenant($this->tenant, function () use ($id) {
        expect(fn () => app()->call([new SendMessage($id), 'handle']))->toThrow(RuntimeException::class);
        expect(Message::query()->find($id))->status->toBe(MessageStatus::Queued)->error->toContain('Invalid To number');
    });
});

it('skips patients without a contact for the channel', function () {
    $noEmail = patient($this->tenant, ['email' => null]);

    actingAsStaff($this->desk)->postJson('/api/messages', ['channel' => 'email', 'patient_ids' => [$this->patient->id, $noEmail->id], 'body' => 'Clinic closed on Monday', 'subject' => 'Notice'])
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('skipped.0.patient_id', $noEmail->id)
        ->assertJsonPath('skipped.0.reason', 'no_recipient');
});

it('renders clinic templates and keeps one default per purpose and channel', function () {
    $templates = collect(actingAsStaff($this->desk)->getJson('/api/message-templates')->assertOk()->json('data'));
    expect($templates->where('purpose', 'payment_reminder')->pluck('locale')->unique()->values()->all())->toContain('en', 'ml');

    actingAsStaff($this->desk)->postJson('/api/message-templates', ['name' => 'X', 'purpose' => 'general', 'channel' => 'sms', 'body' => 'x'])->assertForbidden();
    $id = actingAsStaff($this->owner)->postJson('/api/message-templates', [
        'name' => 'Short reminder', 'purpose' => 'payment_reminder', 'channel' => 'whatsapp', 'body' => 'Pay {{amount_due}} for {{invoice_number}}', 'is_default' => true,
    ])->assertCreated()->json('data.id');

    $defaults = collect(actingAsStaff($this->owner)->getJson('/api/message-templates')->json('data'))
        ->where('purpose', 'payment_reminder')->where('channel', 'whatsapp')->where('locale', 'en')->where('is_default', true);
    expect($defaults->pluck('id')->all())->toBe([$id]);

    actingAsStaff($this->desk)->postJson('/api/messages/preview', ['body' => 'Dear {{patient_name}}', 'patient_id' => $this->patient->id])
        ->assertOk()->assertJsonPath('data.body', 'Dear Anil Kumar');
});

it('lists outstanding invoices and sends payment reminders', function () {
    $invoice = postedInvoiceFor($this, 5);
    Mail::fake();

    actingAsStaff($this->desk)->getJson('/api/payment-reminders')->assertOk()
        ->assertJsonPath('data.0.id', $invoice->id)
        ->assertJsonPath('data.0.days_overdue', 5)
        ->assertJsonPath('data.0.reminder_count', 0)
        ->assertJsonPath('summary.invoices', 1)
        ->assertJsonPath('summary.amount_due_minor', $invoice->total_minor);

    $res = actingAsStaff($this->desk)->postJson('/api/payment-reminders', ['invoice_ids' => [$invoice->id], 'channel' => 'email'])
        ->assertCreated()->assertJsonPath('data.0.status', 'queued')->assertJsonPath('data.0.purpose', 'payment_reminder');
    expect($res->json('data.0.body'))->toContain($invoice->number)->toContain('800.00');
    expect(inTenant($this->tenant, fn () => Message::query()->firstOrFail()->status))->toBe(MessageStatus::Sent);

    actingAsStaff($this->desk)->getJson('/api/payment-reminders')->assertJsonPath('data.0.reminder_count', 1);
    actingAsStaff(staff($this->tenant, Role::Therapist))->getJson('/api/payment-reminders')->assertForbidden();
});

it('sends automatic reminders on the configured days after the due date', function () {
    $due = postedInvoiceFor($this, 7);
    postedInvoiceFor($this, 3); // not a reminder day
    Mail::fake();

    actingAsStaff($this->owner)->putJson('/api/settings/clinic', ['settings' => ['payment_reminders' => [
        'enabled' => true, 'channel' => 'email', 'days_after_due' => [1, 7], 'include_payment_link' => false,
    ]]])->assertOk()->assertJsonPath('data.settings.payment_reminders.enabled', true);

    $this->artisan('invoices:send-payment-reminders --force')->expectsOutputToContain('Sent 1 payment reminder(s).')->assertSuccessful();
    // Once per day: a second run does not repeat it.
    $this->artisan('invoices:send-payment-reminders --force')->expectsOutputToContain('Sent 0 payment reminder(s).');

    $logged = inTenant($this->tenant, fn () => Message::query()->where('purpose', 'payment_reminder')->get());
    expect($logged)->toHaveCount(1)->and($logged[0]->invoice_id)->toBe($due->id)->and($logged[0]->sent_by)->toBeNull();
});

it('keeps messaging to roles that may contact patients', function () {
    actingAsStaff(staff($this->tenant, Role::Therapist))->getJson('/api/messages')->assertForbidden();
    actingAsStaff($this->desk)->getJson('/api/messages')->assertOk()->assertJsonPath('channels.email', true)->assertJsonPath('channels.whatsapp', false);
});
