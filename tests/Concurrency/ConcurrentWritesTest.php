<?php

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Room;
use App\Services\Billing\InvoiceService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/*
 * These tests commit real data and fire N OS processes at MySQL at the same
 * instant, proving the locking strategy (SELECT ... FOR UPDATE on the
 * practitioner/room/patient rows and on the sequence counter) under true
 * concurrency, which in-process tests cannot show.
 */
uses(TestCase::class, DatabaseTruncation::class);

const WORKERS = 8;

/**
 * @param  list<array>  $jobs
 * @return list<array{ok: bool, result?: mixed, error?: string}>
 */
function runConcurrently(string $action, array $jobs): array
{
    $startAt = microtime(true) + 6; // time for every process to boot and connect
    $env = array_merge(getenv(), $_ENV, ['APP_ENV' => 'testing']);
    $env = array_filter($env, fn ($v) => is_scalar($v));
    $processes = [];

    foreach ($jobs as $i => $args) {
        $processes[$i] = proc_open(
            [PHP_BINARY, base_path('tests/Concurrency/worker.php'), $action, (string) $startAt, json_encode($args)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes[$i],
            base_path(),
            $env,
        );
    }

    $results = [];
    foreach ($processes as $i => $process) {
        $out = stream_get_contents($pipes[$i][1]);
        $err = stream_get_contents($pipes[$i][2]);
        proc_close($process);
        $results[$i] = json_decode(trim($out), true) ?? ['ok' => false, 'error' => 'no output: '.$out.$err];
    }

    return $results;
}

it('lets exactly one of eight simultaneous bookings take a practitioner slot', function () {
    ['tenant' => $tenant, 'branch' => $branch] = clinic();
    $therapist = staff($tenant, Role::Therapist);
    $at = nextWorkday('10:00');

    $jobs = collect(range(1, WORKERS))->map(fn () => [
        'tenant_id' => $tenant->id, 'patient_id' => patient($tenant)->id, 'practitioner_id' => $therapist->id,
        'branch_id' => $branch->id, 'local_start' => $at->format('Y-m-d H:i'), 'timezone' => 'Asia/Kolkata', 'duration' => 45,
    ])->all();

    $results = collect(runConcurrently('book', $jobs));

    expect($results->where('ok', true))->toHaveCount(1)
        ->and($results->where('error', 'conflict'))->toHaveCount(WORKERS - 1, 'unexpected errors: '.$results->pluck('error')->filter(fn ($e) => $e && $e !== 'conflict')->join('; '))
        ->and(inTenant($tenant, fn () => Appointment::query()->where('practitioner_id', $therapist->id)->count()))->toBe(1);
})->group('concurrency');

it('serialises overlapping (not identical) requests for the same room', function () {
    ['tenant' => $tenant, 'branch' => $branch] = clinic();
    $room = inTenant($tenant, fn () => Room::query()->firstOrFail());
    $at = nextWorkday('11:00');

    // Different practitioners and patients, staggered start times, one room.
    $jobs = collect(range(0, WORKERS - 1))->map(fn ($i) => [
        'tenant_id' => $tenant->id, 'patient_id' => patient($tenant)->id, 'practitioner_id' => staff($tenant, Role::Therapist)->id,
        'branch_id' => $branch->id, 'room_id' => $room->id, 'local_start' => $at->addMinutes($i * 5)->format('Y-m-d H:i'),
        'timezone' => 'Asia/Kolkata', 'duration' => 45,
    ])->all();

    $results = collect(runConcurrently('book', $jobs));

    expect($results->where('ok', true))->toHaveCount(1)
        ->and($results->where('error', 'conflict'))->toHaveCount(WORKERS - 1);

    // Whatever won, the room never holds two overlapping bookings.
    inTenant($tenant, function () use ($room) {
        $rows = Appointment::query()->where('room_id', $room->id)->orderBy('starts_at')->get();
        foreach ($rows as $i => $row) {
            if ($i > 0) {
                expect($row->starts_at->greaterThanOrEqualTo($rows[$i - 1]->ends_at))->toBeTrue();
            }
        }
    });
})->group('concurrency');

it('gives concurrently posted invoices unique, gapless numbers', function () {
    ['tenant' => $tenant, 'owner' => $owner, 'branch' => $branch] = clinic();
    $patient = patient($tenant);
    $service = service($tenant);

    $drafts = inTenant($tenant, fn () => collect(range(1, WORKERS))->map(fn () => app(InvoiceService::class)->createDraft([
        'patient_id' => $patient->id, 'branch_id' => $branch->id, 'items' => [['service_id' => $service->id]],
    ], $owner->id)->id));

    $results = collect(runConcurrently('post', $drafts->map(fn ($id) => ['tenant_id' => $tenant->id, 'invoice_id' => $id, 'actor_id' => $owner->id])->all()));

    expect($results->where('ok', true))->toHaveCount(WORKERS, $results->pluck('error')->filter()->join('; '))
        ->and($results->pluck('result')->sort()->values()->all())
        ->toBe(collect(range(1, WORKERS))->map(fn ($n) => sprintf('INV-KOC-%06d', $n))->all());
})->group('concurrency');
