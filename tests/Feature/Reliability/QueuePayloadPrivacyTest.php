<?php

use App\Enums\CandidateStage;
use App\Enums\OfferStatus;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidatePortalAccount;
use App\Models\Employee;
use App\Models\User;
use App\Services\CandidatePortalService;
use App\Services\NotificationDispatchService;
use App\Services\OfferService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 8.7 (D8.7-017, SEC-87-01/02): what sits in `jobs` (and so in `failed_jobs`) is ids and
 * safe scalars — never compensation, contact details, message bodies or bearer tokens. These tests
 * push real jobs to the database queue and read the stored payloads.
 */
beforeEach(function (): void {
    config(['queue.default' => 'database']);
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);
});

function queuedPayloads(): string
{
    return DB::table('jobs')->pluck('payload')->implode("\n");
}

test('releasing an offer queues listeners and alerts without CTC, salary or candidate contact details', function (): void {
    $releaser = Employee::factory()->create();
    User::factory()->create(['employee_id' => $releaser->id])->assignRole('chro');
    $recruiter = Employee::factory()->create();
    User::factory()->create(['employee_id' => $recruiter->id])->assignRole('recruiter');
    $candidate = Candidate::factory()->create(['email' => 'secret.candidate@example.test', 'mobile' => '9876501234', 'current_salary' => 4455667, 'expected_salary' => 5566778]);
    $application = CandidateApplication::factory()->create(['candidate_id' => $candidate->id, 'recruiter_id' => $recruiter->id, 'current_stage' => CandidateStage::Selected]);
    $service = app(OfferService::class);
    $offer = $service->create(['offer_code' => 'OFR-PRIV-1', 'candidate_application_id' => $application->id, 'offer_date' => now(), 'offered_ctc' => 7654321, 'fixed_salary' => 6543210, 'offer_letter_body' => '<p>CONFIDENTIAL LETTER WORDING</p>'], $releaser);
    $service->moveTo($offer, OfferStatus::Initiated, $releaser);
    $service->moveTo($offer->fresh(), OfferStatus::Released, $releaser);

    $payloads = queuedPayloads();

    expect(DB::table('jobs')->count())->toBeGreaterThan(0);

    foreach (['7654321', '6543210', '4455667', '5566778', 'secret.candidate@example.test', '9876501234', 'CONFIDENTIAL LETTER WORDING'] as $secret) {
        expect(str_contains($payloads, $secret))->toBeFalse("Queue payload contains {$secret}");
    }
});

test('password-reset and email-change notices never put their token URLs in the queue', function (): void {
    $user = User::factory()->create(['email' => 'staff.member@example.test']);

    $user->notify(app(ResetPassword::class, ['token' => 'RESET-TOKEN-SECRET-123']));
    $user->notify(app(NoticeOfEmailChangeRequest::class, ['newEmail' => 'new.address@example.test', 'blockVerificationUrl' => 'https://example.test/block?signature=BLOCK-SIGNATURE-SECRET']));

    $payloads = queuedPayloads();

    expect(DB::table('jobs')->where('queue', 'notifications')->count())->toBe(2)
        ->and(str_contains($payloads, 'RESET-TOKEN-SECRET-123'))->toBeFalse()
        ->and(str_contains($payloads, 'BLOCK-SIGNATURE-SECRET'))->toBeFalse()
        ->and(str_contains($payloads, 'new.address@example.test'))->toBeFalse();
});

test('in-app alerts are encrypted on the notifications queue and a duplicate alert is claimed once', function (): void {
    $user = User::factory()->create();
    $alerts = app(NotificationDispatchService::class);

    $alerts->alert($user, 'Offers', 'Candidate Priya Sharma accepted', 'Priya Sharma accepted OFR-9', dedupeKey: 'offer-accepted-9');
    $alerts->alert($user, 'Offers', 'Candidate Priya Sharma accepted', 'Priya Sharma accepted OFR-9', dedupeKey: 'offer-accepted-9');

    expect(DB::table('jobs')->where('queue', 'notifications')->count())->toBe(1)
        ->and(str_contains(queuedPayloads(), 'Priya Sharma'))->toBeFalse();
});

arch('every domain event stores model identifiers, not whole models, when queued')
    ->expect('App\Events')
    ->toUseTrait(SerializesModels::class);

test('every queued listener, notification and mailable of the application encrypts its payload or carries ids only', function (): void {
    $root = dirname(__DIR__, 3);
    $missing = [];

    foreach (['Listeners', 'Notifications', 'Mail'] as $directory) {
        foreach (glob($root."/app/{$directory}/{,*/}*.php", GLOB_BRACE) as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen($root.'/app/')));

            if (is_subclass_of($class, ShouldQueue::class) && ! is_subclass_of($class, ShouldBeEncrypted::class)) {
                $missing[] = $class;
            }
        }
    }

    expect($missing)->toBe([]);
});

test('the candidate portal password link is queued encrypted, so neither its token nor response time gives an account away', function (): void {
    $account = CandidatePortalAccount::factory()->create(['email' => 'portal.person@example.test']);

    app(CandidatePortalService::class)->sendPasswordLink('portal.person@example.test');
    app(CandidatePortalService::class)->sendPasswordLink('nobody@example.test');

    expect(DB::table('jobs')->where('queue', 'notifications')->count())->toBe(1)
        ->and(str_contains(queuedPayloads(), 'portal.person@example.test'))->toBeFalse()
        ->and(str_contains(queuedPayloads(), 'signature='))->toBeFalse()
        ->and($account->fresh()->email)->toBe('portal.person@example.test');
});
