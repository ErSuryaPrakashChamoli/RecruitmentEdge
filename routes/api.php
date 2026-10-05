<?php

use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\ApplicationIntakeController;
use App\Http\Controllers\Api\V1\CandidateController;
use App\Http\Controllers\Api\V1\InboundWebhookController;
use App\Http\Controllers\Api\V1\MasterDataController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\RequisitionController;
use App\Http\Middleware\Api\AuthenticateApiCredential;
use App\Http\Middleware\Api\EnsureApiRequest;
use App\Http\Middleware\Api\RequireIdempotencyKey;
use Illuminate\Support\Facades\Route;

/*
| SaaS-6: the tenant API, version 1 (prefix /api/v1, registered in bootstrap/app.php). No session,
| cookie or CSRF: every request carries its credential, and the credential alone decides the tenant.
| No route names a tenant. Platform operations are never exposed here.
*/

Route::middleware([EnsureApiRequest::class.':api', AuthenticateApiCredential::class, 'throttle:api'])->group(function (): void {
    Route::get('me', MeController::class)->name('me');

    Route::middleware('api.scope:master_data:read')->group(function (): void {
        Route::get('departments', [MasterDataController::class, 'departments'])->name('departments.index');
        Route::get('locations', [MasterDataController::class, 'locations'])->name('locations.index');
        Route::get('designations', [MasterDataController::class, 'designations'])->name('designations.index');
    });

    Route::middleware('api.scope:requisitions:read')->group(function (): void {
        Route::get('requisitions', [RequisitionController::class, 'index'])->name('requisitions.index');
        Route::get('requisitions/{id}', [RequisitionController::class, 'show'])->whereNumber('id')->name('requisitions.show');
        Route::get('job-postings', [RequisitionController::class, 'postings'])->name('job-postings.index');
        Route::get('job-postings/{id}', [RequisitionController::class, 'posting'])->whereNumber('id')->name('job-postings.show');
    });

    Route::middleware('api.scope:candidates:read')->group(function (): void {
        Route::get('candidates', [CandidateController::class, 'index'])->name('candidates.index');
        Route::get('candidates/{id}', [CandidateController::class, 'show'])->whereNumber('id')->name('candidates.show');
    });

    Route::middleware('api.scope:applications:read')->group(function (): void {
        Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
        Route::get('applications/{id}', [ApplicationController::class, 'show'])->whereNumber('id')->name('applications.show');
    });

    Route::post('job-postings/{id}/applications', ApplicationIntakeController::class)
        ->middleware(['api.scope:applications:write', RequireIdempotencyKey::class])
        ->whereNumber('id')
        ->name('job-postings.applications.store');
});

// Inbound webhooks: authenticated by the connection's signature; the tenant is the connection's.
Route::post('hooks/{publicKey}', InboundWebhookController::class)
    ->middleware([EnsureApiRequest::class.':hook', 'throttle:api-hooks'])
    ->name('hooks.receive');
