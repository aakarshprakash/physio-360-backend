<?php

use App\Exceptions\DomainRuleViolation;
use App\Exceptions\ImmutableRecord;
use App\Http\Middleware\EnforceAbsoluteSessionTimeout;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenantContext;
use App\Services\Scheduling\SlotConflict;
use App\Tenancy\MissingTenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cookie-based SPA auth (Sanctum) for requests from SANCTUM_STATEFUL_DOMAINS.
        $middleware->statefulApi();
        $middleware->trustProxies(at: '*');
        $middleware->append(SecurityHeaders::class);
        $middleware->api(append: [EnforceAbsoluteSessionTimeout::class]);
        $middleware->alias(['tenant' => SetTenantContext::class]);

        // Tenant context must exist before route-model binding resolves scoped models.
        $middleware->prependToPriorityList(SubstituteBindings::class, SetTenantContext::class);

        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (DomainRuleViolation $e) => response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
            'context' => (object) $e->context,
        ], 422));

        $exceptions->render(fn (SlotConflict $e) => response()->json([
            'message' => $e->getMessage(),
            'code' => 'slot_conflict',
            'conflicts' => $e->conflicts,
        ], 409));

        $exceptions->render(fn (ImmutableRecord $e) => response()->json([
            'message' => $e->getMessage(),
            'code' => 'immutable_record',
        ], 409));

        $exceptions->render(fn (MissingTenantContext $e) => response()->json([
            'message' => 'Not available.',
            'code' => 'no_tenant',
        ], 403));

        // Database-trigger rejections (SIGNAL SQLSTATE '45000').
        $exceptions->render(function (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '45000') {
                return response()->json(['message' => $e->errorInfo[2] ?? 'This record cannot be changed.', 'code' => 'immutable_record'], 409);
            }

            return null;
        });

        $exceptions->dontReport([DomainRuleViolation::class, SlotConflict::class]);
    })->create();
