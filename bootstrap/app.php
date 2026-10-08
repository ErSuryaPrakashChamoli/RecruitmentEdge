<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\Api\RequireApiScope;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceStaffAccess;
use App\Http\Middleware\EnsureCandidateSessionIsCurrent;
use App\Http\Middleware\RequireCandidateStepUp;
use App\Http\Middleware\ResetTenantContext;
use App\Http\Middleware\ResolveTenantForFilamentDownload;
use App\Http\Middleware\ResolveTenantFromRoute;
use App\Http\Middleware\UseCandidateSessionContext;
use App\Services\Api\ApiErrorRenderer;
use App\Services\Api\ApiException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // SaaS-6: the tenant API, version 1 — its own middleware, no session (routes/api.php).
        then: function (): void {
            Route::prefix('api/v1')->name('api.v1.')->group(base_path('routes/api.php'));

            // SaaS-7 (C9): liveness and readiness — outside the web group (no session, no cookie).
            Route::middleware('throttle:120,1')->group(function (): void {
                Route::get('health/live', [HealthController::class, 'live'])->name('health.live');
                Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Candidate portal routes have their own sign-in page (the admin panel handles its own).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('portal', 'portal/*') ? route('portal.login') : route('filament.admin.auth.login'));
        // Provider webhooks authenticate with request signatures, not the session.
        $middleware->preventRequestForgery(except: ['webhooks/*']);
        $middleware->redirectUsersTo(fn (Request $request) => $request->is('portal', 'portal/*') ? route('portal.dashboard') : '/admin');
        // Phase 8.4: a suspended/revoked login or a stale session is signed out before the panel's
        // Authenticate middleware runs, so it lands on the login page instead of a 403.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnforceStaffAccess::class);
        // Phase 8.4: a correlation id for logs and the audit trail.
        $middleware->prepend(AssignRequestId::class);
        // SaaS-1: every request starts with no tenant; only an explicit resolver sets one.
        $middleware->prepend(ResetTenantContext::class);
        // Phase 8.8 (D8.8-001): candidate portal requests use their own session cookie and guard,
        // chosen before the session starts.
        $middleware->web(prepend: [UseCandidateSessionContext::class]);
        // A candidate session left over from before a password change is ended before the
        // candidate guard authenticates the request.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureCandidateSessionIsCurrent::class);
        // SaaS-1: a careers / portal request enters its tenant before anything reads tenant-owned
        // rows (the candidate session check, authentication, route model binding).
        $middleware->prependToPriorityList(EnsureCandidateSessionIsCurrent::class, ResolveTenantFromRoute::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenantForFilamentDownload::class);
        $middleware->alias(['candidate.step-up' => RequireCandidateStepUp::class, 'api.scope' => RequireApiScope::class]);
        // Phase 8.10 (P810-SEC-001): when APP_TRUSTED_HOSTS is set, only those exact host names are
        // served (Laravel skips the check in local and test runs); unset, every Host is served.
        $middleware->trustHosts(at: fn (): array => array_map(fn (string $host): string => '^'.preg_quote($host).'$', config('app.trusted_hosts')), subdomains: false);
        // SaaS-7 (C9): liveness and readiness answer in maintenance mode, like /up — they tell compose
        // and a release whether this container can serve, not whether the site is open.
        $middleware->preventRequestsDuringMaintenance(except: ['health/live', 'health/ready']);
        // Phase 8.8 (SEC-88-11): defensive headers on portal, career and staff sign-in pages.
        $middleware->web(append: [AddSecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // SaaS-6: every /api error has the API's error contract; refusals are not errors to report.
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*') ? ApiErrorRenderer::render($e) : null);
        $exceptions->dontReport([ApiException::class]);
    })->create();
