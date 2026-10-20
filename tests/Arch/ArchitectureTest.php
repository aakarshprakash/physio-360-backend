<?php

use App\Tenancy\BelongsToTenant;

arch('no debugging or unsafe functions ship')
    ->preset()->php()
    ->ignoring('App\Http\Controllers\Api\Staff\ReportController'); // fopen/fputcsv on php://output for CSV streaming

arch('security preset')
    ->preset()->security()
    ->ignoring('Database\Seeders\DemoSeeder'); // deterministic demo data only

arch('every tenant-owned model is tenant-scoped')
    ->expect('App\Models')
    ->classes()
    ->toUseTrait(BelongsToTenant::class)
    ->ignoring([
        'App\Models\Concerns',
        'App\Models\Currency',       // reference data
        'App\Models\Tenant',         // the tenant itself
        'App\Models\PlatformAdmin',  // SYSNAC operators
        'App\Models\AuditLog',       // tenant_id nullable; readers filter explicitly
    ]);

arch('controllers do not read env() directly')
    ->expect('App\Http')
    ->not->toUse('env');

arch('enums are backed')
    ->expect('App\Enums')
    ->toBeStringBackedEnums();

/*
 * Global scopes do not apply to the query builder. Raw table access is only
 * allowed where tenant filtering is explicit and reviewed.
 */
it('confines raw query-builder access to reviewed places', function () {
    $allowed = [
        'app/Reports/',                                   // explicit tenant_id filters
        'app/Http/Controllers/Api/Platform/',             // cross-tenant by design, counts only
        'app/Services/Scheduling/BookingService.php',     // SET TRANSACTION ISOLATION LEVEL
    ];

    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
        if (collect($allowed)->contains(fn ($prefix) => str_starts_with($relative, $prefix))) {
            continue;
        }

        if (preg_match('/DB::(table|select|insert|update|delete|statement|unprepared|connection|raw)\s*\(/', file_get_contents($file->getPathname()), $m)) {
            $offenders[] = "{$relative} uses DB::{$m[1]}()";
        }
    }

    expect($offenders)->toBe([]);
});

it('never puts tokens or patient data in browser storage hints', function () {
    // The SPA must not be told to cache API responses.
    expect(file_get_contents(base_path('app/Http/Middleware/SecurityHeaders.php')))->toContain('no-store');
});
