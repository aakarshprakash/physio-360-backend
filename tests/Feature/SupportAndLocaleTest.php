<?php

use App\Enums\Role;
use App\Models\PlatformAdmin;
use App\Notifications\SupportTicketNotification;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner] = clinic();
    $this->therapist = staff($this->tenant, Role::Therapist);
    $this->admin = PlatformAdmin::query()->forceCreate(['name' => 'Asha', 'email' => 'asha@sysnac.test', 'password' => 'Secret123']);
    config(['physio.support.email' => 'support@sysnac.test']);
});

function ticket(object $t, $user, array $data = [])
{
    return actingAsStaff($user)->postJson('/api/support/tickets', array_merge([
        'subject' => 'Invoice PDF shows the wrong GSTIN', 'category' => 'bug', 'priority' => 'high',
        'body' => 'Since yesterday the GSTIN on invoices is blank.',
    ], $data));
}

it('opens numbered tickets and emails the SYSNAC desk', function () {
    Notification::fake();

    ticket($this, $this->therapist)->assertCreated()
        ->assertJsonPath('data.number', 'SUP-00001')
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.messages.0.author_type', 'clinic');
    ticket($this, $this->owner, ['subject' => 'Training request'])->assertJsonPath('data.number', 'SUP-00002');

    Notification::assertSentTo(new AnonymousNotifiable, SupportTicketNotification::class,
        fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'support@sysnac.test' && $n->event === 'opened');
});

it('shows staff their own tickets and managers all of the clinic', function () {
    $mine = ticket($this, $this->therapist)->json('data.id');
    ticket($this, $this->owner);

    actingAsStaff($this->therapist)->getJson('/api/support/tickets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('contact.email', 'support@sysnac.test');
    actingAsStaff($this->owner)->getJson('/api/support/tickets')->assertJsonCount(2, 'data');
    actingAsStaff(staff($this->tenant, Role::Receptionist))->getJson("/api/support/tickets/{$mine}")->assertForbidden();
});

it('runs the conversation between the clinic and the console', function () {
    Notification::fake();
    $id = ticket($this, $this->therapist)->json('data.id');
    ['owner' => $otherOwner] = clinic();
    ticket($this, $otherOwner);

    // Console sees every clinic's open tickets, highest priority first.
    freshBrowser()->actingAs($this->admin, 'platform')->getJson('/api/platform/support/tickets')->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('summary.awaiting_sysnac', 2)->assertJsonPath('data.0.clinic.name', $this->tenant->name);

    freshBrowser()->actingAs($this->admin, 'platform')->postJson("/api/platform/support/tickets/{$id}/messages", ['body' => 'Fixed, please re-download the PDF.'])
        ->assertOk()->assertJsonPath('data.status', 'waiting_on_clinic')->assertJsonPath('data.messages.1.author_name', 'Asha (SYSNAC)');
    Notification::assertSentTo(new AnonymousNotifiable, SupportTicketNotification::class,
        fn ($n, $c, $notifiable) => $n->event === 'sysnac_reply' && $notifiable->routes['mail'] === $this->therapist->email);

    actingAsStaff($this->therapist)->postJson("/api/support/tickets/{$id}/messages", ['body' => 'Works now, thanks!'])
        ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonCount(3, 'data.messages');
    actingAsStaff($this->therapist)->postJson("/api/support/tickets/{$id}/status", ['status' => 'closed'])->assertOk();
    actingAsStaff($this->therapist)->postJson("/api/support/tickets/{$id}/messages", ['body' => 'One more thing'])
        ->assertUnprocessable()->assertJsonPath('code', 'ticket_closed');

    // Threads are append-only.
    expect(fn () => DB::table('support_ticket_messages')->update(['body' => 'x']))->toThrow(QueryException::class);
    // Staff of another clinic cannot reach it.
    actingAsStaff($otherOwner)->getJson("/api/support/tickets/{$id}")->assertNotFound();
});

it('accepts Malayalam as an interface and document language', function () {
    actingAsStaff($this->therapist)->putJson('/api/auth/profile', ['locale' => 'ml'])->assertOk()->assertJsonPath('data.user.locale', 'ml');
    actingAsStaff($this->owner)->putJson('/api/settings/clinic', ['locale' => 'ml'])->assertOk()->assertJsonPath('data.locale', 'ml');
    actingAsStaff($this->owner)->putJson('/api/auth/profile', ['locale' => 'fr'])->assertJsonValidationErrors('locale');
});

it('keeps PDF translations complete in every language', function () {
    $keys = function (array $a, string $p = '') use (&$keys): array {
        return collect($a)->flatMap(fn ($v, $k) => is_array($v) ? $keys($v, "{$p}{$k}.") : ["{$p}{$k}"])->sort()->values()->all();
    };
    $en = $keys(require lang_path('en/pdf.php'));

    expect($keys(require lang_path('ar/pdf.php')))->toBe($en)
        ->and($keys(require lang_path('ml/pdf.php')))->toBe($en);
});
