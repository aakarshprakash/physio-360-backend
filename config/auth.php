<?php

use App\Models\Patient;
use App\Models\PlatformAdmin;
use App\Models\User;

/*
| Three independent identities share one session store but never one guard:
|  - web      : clinic staff (Sanctum SPA cookie auth, tenant-scoped)
|  - patient  : patients using the PWA (tenant-scoped, separate provider)
|  - platform : SYSNAC console operators (cross-tenant, no tenant context)
*/
return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'patient' => [
            'driver' => 'session',
            'provider' => 'patients',
        ],
        'platform' => [
            'driver' => 'session',
            'provider' => 'platform_admins',
        ],
    ],

    'providers' => [
        // "tenantless" providers resolve identities before a tenant context exists.
        'users' => [
            'driver' => 'tenantless-eloquent',
            'model' => User::class,
        ],
        'patients' => [
            'driver' => 'tenantless-eloquent',
            'model' => Patient::class,
        ],
        'platform_admins' => [
            'driver' => 'eloquent',
            'model' => PlatformAdmin::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    | Absolute session lifetime (minutes) regardless of activity. The idle
    | timeout is SESSION_LIFETIME (sliding). See docs/architecture.md (NFRs).
    */
    'absolute_timeout' => (int) env('AUTH_ABSOLUTE_TIMEOUT', 720),

];
