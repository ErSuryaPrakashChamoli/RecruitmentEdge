<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceStaffAccess;
use App\Http\Middleware\EnsureCandidateSessionIsCurrent;
use App\Http\Middleware\RequireCandidateStepUp;
use App\Http\Middleware\ResetTenantContext;
use App\Http\Middleware\ResolveTenantForFilamentDownload;
use App\Http\Middleware\ResolveTenantFromRoute;
use App\Http\Middleware\UseCandidateSessionContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
        $middleware->alias(['candidate.step-up' => RequireCandidateStepUp::class]);
        // Phase 8.10 (P810-SEC-001): when APP_TRUSTED_HOSTS is set, only those exact host names are
        // served (Laravel skips the check in local and test runs); unset, every Host is served.
        $middleware->trustHosts(at: fn (): array => array_map(fn (string $host): string => '^'.preg_quote($host).'$', config('app.trusted_hosts')), subdomains: false);
        // Phase 8.8 (SEC-88-11): defensive headers on portal, career and staff sign-in pages.
        $middleware->web(append: [AddSecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
