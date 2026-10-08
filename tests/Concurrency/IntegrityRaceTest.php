<?php

use App\Enums\CandidateStage;
use App\Enums\IncentiveCalculationStatus;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferRevisionStatus;
use App\Enums\OfferStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentIncentiveSlab;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\CandidateJoiningService;
use App\Services\Identity\AuthorityGuard;
use App\Services\Identity\LastChroProtectedException;
use App\Services\Identity\StaffAccessService;
use App\Services\IncentiveApprovalService;
use App\Services\InterviewService;
use App\Services\OfferService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concurrency\Race;

/**
 * Phase 8.9 (P89-DQ-001…006, P89-PERF-021): real two-transaction races on MySQL 8.4 / REPEATABLE
 * READ. In each test the holder's transaction is open and uncommitted while a second process, holding
 * copies loaded before the holder commits, runs the conflicting action. The fix is proven when the
 * contender blocks on the lock and then decides on the committed outcome.
 *
 * Run with: vendor/bin/pest -c phpunit.concurrency.xml
 */
beforeEach(function (): void {
    Race::prepareDatabase();
    Storage::fake('local');

    if (! DB::table('roles')->exists()) {
        $this->seed(RolePermissionSeeder::class);
    }
});

test('two payments of one incentive record one payment (DQ-001)', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Payable, 'amount' => 1000]);

    $race = Race::run(
        holder: fn () => app(IncentiveApprovalService::class)->pay(RecruiterIncentiveCalculation::query()->find($calculation->id), 1000.0, now(), 'REF-A'),
        contender: fn () => app(IncentiveApprovalService::class)->pay(RecruiterIncentiveCalculation::query()->find($calculation->id), 1000.0, now(), 'REF-B'),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(DomainException::class)
        ->and($calculation->payments()->count())->toBe(1)
        ->and($calculation->fresh()->status)->toBe(IncentiveCalculationStatus::Paid);
});

test('two joinings in one month are priced at consecutive slab positions (DQ-002)', function (): void {
    $recruiter = Employee::factory()->create();
    $rule = RecruitmentIncentiveRule::factory()->slabByCount()->create();
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 1, 'achievement_max' => 1.5, 'amount' => 1000]);
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 2, 'achievement_max' => null, 'amount' => 2000]);
    $joinings = collect([1, 2])->map(fn () => CandidateJoining::factory()->withAcceptedOffer()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id, 'current_stage' => CandidateStage::JoiningConfirmed])->id,
        'status' => JoiningStatus::Confirmed,
    ]));

    $race = Race::run(
        holder: fn () => app(CandidateJoiningService::class)->markJoined(CandidateJoining::query()->find($joinings[0]->id), now()),
        contender: fn () => app(CandidateJoiningService::class)->markJoined(CandidateJoining::query()->find($joinings[1]->id), now()),
    );

    $calculations = RecruiterIncentiveCalculation::query()->where('incentive_rule_id', $rule->id)->orderBy('id')->get();

    expect($race['blocked'])->toBeTrue()
        ->and($race['completed'])->toBeTrue()
        ->and($calculations->pluck('occurrence_count')->all())->toBe([1, 2])
        ->and($calculations->map(fn ($c) => (float) $c->amount)->all())->toBe([1000.0, 2000.0]);
});

test('a dropout recorded while the join commits is refused (DQ-003)', function (): void {
    $joining = CandidateJoining::factory()->withAcceptedOffer()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['current_stage' => CandidateStage::JoiningConfirmed])->id,
        'status' => JoiningStatus::Confirmed,
    ]);
    $reason = RecruitmentRejectionReason::factory()->create();

    $race = Race::run(
        holder: fn () => app(CandidateJoiningService::class)->markJoined(CandidateJoining::query()->find($joining->id), now()),
        contender: fn () => app(CandidateJoiningService::class)->markDropout(CandidateJoining::query()->find($joining->id), $reason),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(DomainException::class)
        ->and($joining->fresh()->status)->toBe(JoiningStatus::Joined);
});

test('a revision released while the offer is being accepted is refused (DQ-004)', function (): void {
    $releaser = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $offer = Offer::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['current_stage' => CandidateStage::OfferReleased])->id,
        'status' => OfferStatus::Released,
        'offered_ctc' => 1000000,
    ]);
    $revision = app(OfferService::class)->requestRevision($offer, ['offered_ctc' => 1300000], 'Counter-offer', $releaser);

    $race = Race::run(
        holder: fn () => app(OfferService::class)->moveTo(Offer::query()->find($offer->id), OfferStatus::Accepted, $releaser->employee),
        contender: fn () => app(OfferService::class)->releaseRevision(OfferRevision::query()->find($revision->id), User::query()->find($releaser->id)),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(DomainException::class)
        ->and($offer->fresh()->status)->toBe(OfferStatus::Accepted)
        ->and($offer->fresh()->offered_ctc)->toEqual('1000000.00')
        ->and($revision->fresh()->status)->toBe(OfferRevisionStatus::Pending);
});

test('a stage move racing a rejection is refused (DQ-005)', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Screened]);
    $reason = RecruitmentRejectionReason::factory()->create();

    $race = Race::run(
        holder: fn () => app(StageTransitionService::class)->reject(CandidateApplication::query()->find($application->id), $reason),
        contender: fn () => app(StageTransitionService::class)->transitionTo(CandidateApplication::query()->find($application->id), CandidateStage::Shortlisted),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(DomainException::class)
        ->and($application->fresh()->current_stage)->toBe(CandidateStage::Screened)
        ->and($application->stageHistory()->count())->toBe(1);
});

test('two offers raised at once leave one open offer (DQ-006)', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);
    $create = fn (string $code) => app(OfferService::class)->create(['offer_code' => $code, 'candidate_application_id' => $application->id, 'offer_date' => now()]);

    $race = Race::run(
        holder: fn () => $create('OFR-RACE-A'),
        contender: fn () => $create('OFR-RACE-B'),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(DomainException::class)
        ->and(Offer::query()->where('candidate_application_id', $application->id)->count())->toBe(1);
});

test('an interview transition waits for the application before locking the interview (P89-PERF-021)', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::InterviewScheduled]);
    $interview = Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Scheduled, 'scheduled_at' => now()->subHour()]);

    // The holder holds the application, as a rejection and its cascade do (application → its
    // interviews). A contender that locked the interview first and then waited for the application
    // would be one half of a deadlock; it must wait for the application holding nothing.
    $race = Race::run(
        holder: fn () => DB::table('candidate_applications')->where('id', $application->id)->lockForUpdate()->first(),
        contender: fn () => app(InterviewService::class)->markNoShow(Interview::query()->find($interview->id)),
        whileBlocked: fn () => DB::table('performance_schema.data_locks')
            ->where('OBJECT_SCHEMA', DB::raw('DATABASE()'))
            ->where('OBJECT_NAME', 'interviews')
            ->where('LOCK_TYPE', 'RECORD')
            ->where('LOCK_STATUS', 'GRANTED')
            ->count(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(0)
        ->and($race['completed'])->toBeTrue()
        ->and($interview->fresh()->status)->toBe(InterviewStatus::NoShow);
});

test('two concurrent suspensions can never leave the organisation without a CHRO (P89-SEC-004)', function (): void {
    $first = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $second = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    User::query()->whereKeyNot([$first->id, $second->id])->whereHas('roles', fn ($q) => $q->where('name', 'chro'))->get()->each->removeRole('chro');

    $race = Race::run(
        holder: fn () => app(StaffAccessService::class)->suspend(User::query()->find($first->id), null, 'Leave'),
        contender: fn () => app(StaffAccessService::class)->suspend(User::query()->find($second->id), null, 'Leave'),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(LastChroProtectedException::class)
        ->and(app(AuthorityGuard::class)->effectiveChroIds()->all())->toBe([$second->id]);
});
