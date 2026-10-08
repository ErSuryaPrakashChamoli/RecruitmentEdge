<?php

use App\Enums\CandidateStage;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\InterviewResult;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeType;
use App\Enums\RequisitionStatus;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Interviewer;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\ApplicationAssignmentService;
use App\Services\CandidateJoiningService;
use App\Services\Distribution\JobDistributionService;
use App\Services\EmployeeConversionService;
use App\Services\InterviewFeedbackService;
use App\Services\InterviewService;
use App\Services\OfferService;
use App\Services\Outcomes\OutcomeEvaluator;
use App\Services\RequisitionApprovalService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/*
 * Phase 8.10 final release readiness: the whole hiring lifecycle once, end to end, through the
 * services the panel, the careers site and the scheduler use — requisition → approval → publishing →
 * application → recruiter → screening → interview → feedback → selection → offer → release →
 * acceptance → joining → Joined → employee → hiring outcome → retention observation. Each stage's
 * own rules and negative paths are covered by its lifecycle suite; this proves they compose.
 */
test('a requisition becomes an observed, retained hire end to end', function (): void {
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);
    CandidateSource::factory()->create(['name' => 'Website', 'code' => CandidateSource::CODE_WEBSITE]);
    RecruitmentIncentiveRule::factory()->fixed(1500)->create(['trigger_event' => IncentiveTriggerEvent::Joining, 'effective_from' => now()->subYear()]);

    $chroEmployee = Employee::factory()->create();
    $chro = User::factory()->create(['employee_id' => $chroEmployee->id])->assignRole('chro');
    $vpEmployee = Employee::factory()->reportingTo($chroEmployee)->create();
    $vp = User::factory()->create(['employee_id' => $vpEmployee->id])->assignRole('vp_hr');
    $managerEmployee = Employee::factory()->reportingTo($vpEmployee)->create();
    $manager = User::factory()->create(['employee_id' => $managerEmployee->id])->assignRole('manager');
    $recruiterEmployee = Employee::factory()->reportingTo($managerEmployee)->create();
    $recruiter = User::factory()->create(['employee_id' => $recruiterEmployee->id])->assignRole('recruiter');
    $interviewerEmployee = Interviewer::factory()->create(['employee_id' => Employee::factory()->reportingTo($managerEmployee)->create()->id])->employee;
    $interviewer = User::factory()->create(['employee_id' => $interviewerEmployee->id])->assignRole('recruiter');

    // 1–3. Requisition raised by the manager, approved by someone else, opened and published.
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Draft, 'manager_id' => $managerEmployee->id, 'created_by' => $managerEmployee->id]);
    $requisition->recruiters()->attach($recruiterEmployee->id);
    $approvals = app(RequisitionApprovalService::class);

    actingAs($manager);
    $approvals->submitForApproval($requisition, $managerEmployee);
    expect(fn () => $approvals->approve($requisition->fresh(), $managerEmployee))->toThrow(DomainException::class);

    actingAs($vp);
    $approvals->approve($requisition->fresh(), $vpEmployee);
    $approvals->open($requisition->fresh(), $vpEmployee);
    $distribution = app(JobDistributionService::class);
    $posting = $distribution->savePosting($requisition->fresh(), ['title' => 'Field Sales Executive', 'description' => str_repeat('Drive enterprise sales across the region. ', 3)], $vpEmployee);
    $distribution->publish($posting, ['career_site'], $vpEmployee);

    expect($requisition->fresh()->status)->toBe(RequisitionStatus::Open);

    // 4–5. A candidate applies on the careers site (anonymous).
    Auth::guard('web')->logout();
    $this->get(route('careers.show', $posting->fresh()->public_slug))->assertOk()->assertSee('Field Sales Executive');
    $this->post(route('careers.apply', $posting->fresh()->public_slug), [
        'full_name' => 'Meera Joshi', 'email' => 'meera.joshi@example.test', 'mobile' => '9876543210', 'current_city' => 'Pune',
        'resume' => UploadedFile::fake()->create('cv.pdf', 60, 'application/pdf'), 'privacy_consent' => '1',
    ])->assertRedirect();

    $application = CandidateApplication::query()->where('requisition_id', $requisition->id)->sole();
    expect($application->candidate->full_name)->toBe('Meera Joshi')
        ->and($application->current_stage)->toBe(CandidateStage::Sourced);

    // 6. The manager assigns the recruiter.
    actingAs($manager);
    if ((int) $application->recruiter_id !== $recruiterEmployee->id) {
        app(ApplicationAssignmentService::class)->reassignRecruiter($application, $recruiterEmployee, $manager, 'Owner for this requisition');
    }
    expect($application->fresh()->recruiter_id)->toBe($recruiterEmployee->id);

    // 7–10. Screening, interview, feedback, selection.
    actingAs($recruiter);
    app(StageTransitionService::class)->advance($application->fresh(), CandidateStage::Screened, $recruiterEmployee);
    $interview = app(InterviewService::class)->schedule($application->fresh(), ['interviewer_id' => $interviewerEmployee->id, 'scheduled_at' => now()->addDay(), 'mode' => 'video_call', 'round_number' => 1], $recruiterEmployee);
    app(InterviewFeedbackService::class)->submit($interview, ['recommendation' => 'recommend', 'feedback' => 'Strong closer.', 'ratings' => ['technical' => 4]], $interviewer);
    app(InterviewService::class)->complete($interview->fresh(), InterviewResult::Selected, $recruiterEmployee);
    app(InterviewService::class)->selectCandidate($interview->fresh(), $recruiterEmployee);

    expect($application->fresh()->current_stage)->toBe(CandidateStage::Selected);

    // 11–13. Offer created, released by an offers.release holder, accepted: the joining follows.
    $offers = app(OfferService::class);
    $offer = $offers->create(['candidate_application_id' => $application->id, 'offer_code' => 'OFR-E2E-1', 'offered_ctc' => 900000, 'fixed_salary' => 800000, 'offer_date' => now(), 'offer_expiry' => now()->addWeek(), 'expected_joining_date' => now()->addWeeks(2)], $recruiterEmployee);
    $offers->moveTo($offer, OfferStatus::Initiated, $recruiterEmployee);
    actingAs($vp);
    $offers->moveTo($offer->fresh(), OfferStatus::Released, $vpEmployee);
    actingAs($recruiter);
    $offers->moveTo($offer->fresh(), OfferStatus::Accepted, $recruiterEmployee);

    $joining = CandidateJoining::query()->where('candidate_application_id', $application->id)->sole();
    expect($joining->offer_id)->toBe($offer->id)
        ->and($application->fresh()->current_stage)->toBe(CandidateStage::OfferAccepted);

    // 14–15. Joining confirmed and marked Joined: the incentive is priced once; a second Joined is refused.
    $joinings = app(CandidateJoiningService::class);
    $joinings->confirm($joining, $recruiterEmployee);
    $joinings->markJoined($joining->fresh(), now(), $recruiterEmployee);

    expect($joining->fresh()->status)->toBe(JoiningStatus::Joined)
        ->and($application->fresh()->current_stage)->toBe(CandidateStage::Joined)
        ->and(RecruiterIncentiveCalculation::query()->where('candidate_application_id', $application->id)->count())->toBe(1)
        ->and(fn () => $joinings->markJoined($joining->fresh(), now(), $recruiterEmployee))->toThrow(DomainException::class);

    // 16. Converted to an employee once.
    actingAs($chro);
    $employee = app(EmployeeConversionService::class)->convert($joining->fresh(), $chro, $managerEmployee->id);
    expect($employee->candidate_id)->toBe($application->candidate_id)
        ->and(fn () => app(EmployeeConversionService::class)->convert($joining->fresh(), $chro, $managerEmployee->id))->toThrow(DomainException::class, 'already been converted');

    // 17. The hiring outcome is captured from the joining record.
    app(OutcomeEvaluator::class)->evaluate();
    $snapshot = HiringOutcomeSnapshot::query()->where('candidate_application_id', $application->id)->sole();
    expect(HiringOutcome::query()->where('candidate_application_id', $application->id)->current()->pluck('outcome_type')->all())
        ->toContain(OutcomeType::OfferReleased, OutcomeType::OfferAccepted, OutcomeType::Joined, OutcomeType::TimeToHire);

    // 18. The 30-day status checkpoint observes the employee as active.
    $this->travel(32)->days();
    app(OutcomeEvaluator::class)->evaluate();
    expect(HiringOutcome::query()->where('hiring_outcome_snapshot_id', $snapshot->id)->where('outcome_type', OutcomeType::StatusObserved30d)->current()->sole()->result)->toBe(OutcomeResult::Active);

    // Audit: the critical facts carry an actor trail.
    expect($offer->statusHistory()->count())->toBeGreaterThanOrEqual(4)
        ->and($application->stageHistory()->get()->pluck('new_stage')->all())->toContain(CandidateStage::Selected, CandidateStage::OfferAccepted, CandidateStage::Joined)
        ->and(AuditLog::query()->where('auditable_type', CandidateJoining::class)->where('auditable_id', $joining->id)->exists())->toBeTrue()
        ->and($joining->fresh()->created_by)->not->toBeNull();
});
