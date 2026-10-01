<?php

use App\Http\Controllers\Portal\ApplicationController;
use App\Http\Controllers\Portal\AuthController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\DocumentController;
use App\Http\Controllers\Portal\PasswordController;
use App\Http\Controllers\Portal\ProfileController;
use App\Http\Controllers\Portal\SchedulingController;
use App\Http\Controllers\Portal\StepUpController;
use App\Http\Middleware\EnsureCandidatePortalAccountIsActive;
use App\Http\Middleware\EnsureCandidateSessionIsCurrent;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Candidate Portal (Phase 4)
|--------------------------------------------------------------------------
|
| Candidate-facing routes on the separate `candidate` guard — never the Filament admin panel.
| Loaded from routes/web.php with the `portal` prefix and `portal.` name prefix. Records are
| addressed only by public references (application code, ULIDs), and every lookup is scoped to
| the signed-in candidate or authorised by a temporary signed URL.
|
*/

Route::middleware('guest:candidate')->group(function (): void {
    Route::get('login', [AuthController::class, 'create'])->name('login');
    Route::post('login', [AuthController::class, 'store'])->middleware('throttle:portal-auth')->name('login.store');
    Route::get('password/forgot', [PasswordController::class, 'forgot'])->name('password.forgot');
    Route::post('password/forgot', [PasswordController::class, 'sendLink'])->middleware('throttle:portal-links')->name('password.email');
});

Route::middleware(['signed', 'throttle:portal-auth'])->group(function (): void {
    Route::get('password/set/{account:public_id}', [PasswordController::class, 'edit'])->name('password.edit');
    Route::post('password/set/{account:public_id}', [PasswordController::class, 'update'])->name('password.update');
});

// Self-scheduling: a valid signature OR the owning signed-in candidate (checked in the controller).
// Phase 8.8 (D8.8-001): a candidate session left over from before a password change is ended first.
Route::middleware([EnsureCandidateSessionIsCurrent::class, 'throttle:portal-actions'])->group(function (): void {
    Route::get('schedule/{invitation}', [SchedulingController::class, 'show'])->name('schedule.show');
    Route::post('schedule/{invitation}', [SchedulingController::class, 'book'])->name('schedule.book');
    Route::get('bookings/{booking}', [SchedulingController::class, 'showBooking'])->name('bookings.show');
    Route::post('bookings/{booking}/reschedule', [SchedulingController::class, 'reschedule'])->name('bookings.reschedule');
    Route::post('bookings/{booking}/cancel', [SchedulingController::class, 'cancel'])->name('bookings.cancel');
});

Route::middleware([EnsureCandidateSessionIsCurrent::class, 'auth:candidate', EnsureCandidatePortalAccountIsActive::class, 'throttle:portal-actions'])->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('logout', [AuthController::class, 'destroy'])->name('logout');

    // Phase 8.8 (D8.8-001): email one-time-code step-up. A reusable capability — routes that need
    // it use the `candidate.step-up` middleware; none does yet.
    Route::get('verify', [StepUpController::class, 'show'])->name('step-up.show');
    Route::post('verify/send', [StepUpController::class, 'send'])->name('step-up.send');
    Route::post('verify', [StepUpController::class, 'verify'])->name('step-up.verify');

    Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
    Route::post('applications/{application}/interviews/{round}/confirm', [ApplicationController::class, 'confirmInterview'])->whereNumber('round')->name('interviews.confirm');
    Route::post('applications/{application}/interviews/{round}/reschedule-request', [ApplicationController::class, 'requestReschedule'])->whereNumber('round')->name('interviews.reschedule-request');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
});
