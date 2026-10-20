<?php

namespace Tests;

use App\Tenancy\TenantContext;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Seed currencies + role matrix once after migrating (RefreshDatabase). */
    protected bool $seed = true;

    protected string $seeder = ReferenceDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Behave like the SPA: Sanctum only starts a session for stateful origins.
        $this->withHeader('Referer', 'http://localhost:5173/');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }
}
