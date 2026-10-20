<?php

namespace App\Providers;

use App\Models\PlatformAdmin;
use App\Support\Database\BlueprintMacros;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantlessEloquentUserProvider;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        BlueprintMacros::register();

        Model::shouldBeStrict(! $this->app->isProduction());

        Auth::provider('tenantless-eloquent', fn ($app, array $config) => new TenantlessEloquentUserProvider($app['hash'], $config['model']));

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(8)->letters()->numbers());

        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email));

        $this->configureRateLimiting();
        $this->configureApiDocs();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));

        // Credential endpoints: per account+IP and per IP, to slow both targeted and spray attacks.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);

        RateLimiter::for('portal-booking', fn (Request $request) => Limit::perHour(20)->by('patient:'.Auth::guard('patient')->id()));

        RateLimiter::for('public-enquiry', fn (Request $request) => [Limit::perMinute(3)->by($request->ip()), Limit::perDay(30)->by($request->ip())]);

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // Outbound messaging costs money per message and can be abused for spam.
        RateLimiter::for('messaging', fn (Request $request) => [
            Limit::perMinute(20)->by('user:'.$request->user()?->id),
            Limit::perHour(300)->by('tenant:'.$request->user()?->tenant_id),
        ]);

        RateLimiter::for('support', fn (Request $request) => Limit::perHour(30)->by('user:'.$request->user()?->id));
    }

    private function configureApiDocs(): void
    {
        Gate::define('viewApiDocs', fn ($user = null) => $this->app->environment('local')
            || Auth::guard('platform')->user() instanceof PlatformAdmin);

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->secure(SecurityScheme::apiKey('cookie', 'physio360_session'));
        });
    }
}
