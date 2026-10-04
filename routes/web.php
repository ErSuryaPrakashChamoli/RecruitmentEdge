<?php

use App\Http\Controllers\Careers\CareerSiteController;
use App\Http\Controllers\Integrations\CalendarOAuthController;
use App\Http\Controllers\PrivateFileController;
use App\Http\Controllers\QueueHealthController;
use App\Http\Controllers\Webhooks\CommunicationWebhookController;
use App\Http\Middleware\EnforceStaffAccess;
use App\Http\Middleware\EnsureStaffMfa;
use App\Http\Middleware\ResolveTenantFromRoute;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// SaaS-1: the candidate portal and the careers site live under their tenant (ResolveTenantFromRoute).
Route::prefix('portal/{tenant}')->middleware(ResolveTenantFromRoute::class)->name('portal.')->group(base_path('routes/portal.php'));

// Phase 5 provider callbacks — authenticated by provider signature, not session/CSRF.
Route::prefix('webhooks')->middleware('throttle:webhooks')->group(function (): void {
    Route::get('communications/{provider}', [CommunicationWebhookController::class, 'verify'])->name('webhooks.communications.verify');
    Route::post('communications/{provider}', [CommunicationWebhookController::class, 'handle'])->name('webhooks.communications');
});

// Phase 5 OAuth connect flow for an employee's own calendar (staff session).
// SaaS-1: connecting starts inside a tenant; the provider's fixed (pre-registered) callback URL
// carries no tenant, so the controller keeps it in the session beside the OAuth state.
Route::middleware(['auth', 'throttle:calendar-oauth'])->prefix('integrations/calendar')->name('integrations.calendar.')->group(function (): void {
    Route::get('{tenant}/{provider}/connect', [CalendarOAuthController::class, 'redirect'])->middleware(ResolveTenantFromRoute::class)->name('connect');
    Route::get('{provider}/callback', [CalendarOAuthController::class, 'callback'])->name('callback');
});

// Phase 8.8 (SEC-88-17): private files (resumes, documents) — only for the signed-in staff user the
// short-lived signed link was issued to; replaces the open `storage/{path}` route.
Route::get('files/private', PrivateFileController::class)
    ->middleware(['signed:relative', EnforceStaffAccess::class, 'auth:web', EnsureStaffMfa::class, 'throttle:private-files'])
    ->name('files.private');

// Phase 5 public career site (applications flow into the existing candidate pipeline).
// Phase 8.7 (D8.7-021): queue health for external monitoring (administrators or a bearer token).
Route::get('health/queue', QueueHealthController::class)->middleware('throttle:60,1')->name('health.queue');

Route::prefix('careers/{tenant}')->middleware(ResolveTenantFromRoute::class)->name('careers.')->group(function (): void {
    Route::get('/', [CareerSiteController::class, 'index'])->name('index');
    Route::get('feed.xml', [CareerSiteController::class, 'feed'])->name('feed');
    Route::get('{slug}', [CareerSiteController::class, 'show'])->name('show');
    Route::post('{slug}/apply', [CareerSiteController::class, 'apply'])->middleware('throttle:career-apply')->name('apply');
    Route::get('{slug}/applied', [CareerSiteController::class, 'applied'])->name('applied');
});
