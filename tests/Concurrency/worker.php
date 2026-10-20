<?php

/*
 * Child process for concurrency tests. Boots the app, waits for a shared start
 * instant so all workers hit MySQL together, performs one operation through
 * the real service layer and prints a JSON result.
 *
 * Usage: php worker.php <action> <start-at-unix-float> '<json-args>'
 */

use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Billing\InvoiceService;
use App\Services\Scheduling\BookingRequest;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\SlotConflict;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $startAt, $json] = $argv;
$args = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
$tenant = Tenant::query()->findOrFail($args['tenant_id']);

// Warm the connection, then spin until the shared start instant.
DB::select('select 1');
while (microtime(true) < (float) $startAt) {
    usleep(100);
}

try {
    $result = app(TenantContext::class)->run($tenant, fn () => match ($action) {
        'book' => app(BookingService::class)->book(new BookingRequest(
            patientId: $args['patient_id'],
            practitionerId: $args['practitioner_id'],
            branchId: $args['branch_id'],
            localStart: CarbonImmutable::parse($args['local_start'], $args['timezone']),
            durationMinutes: $args['duration'],
            timezone: $args['timezone'],
            roomId: $args['room_id'] ?? null,
        ))->id,
        'post' => app(InvoiceService::class)->post(Invoice::query()->findOrFail($args['invoice_id']), $args['actor_id'])->number,
    });

    echo json_encode(['ok' => true, 'result' => $result]);
} catch (SlotConflict) {
    echo json_encode(['ok' => false, 'error' => 'conflict']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()]);
}
