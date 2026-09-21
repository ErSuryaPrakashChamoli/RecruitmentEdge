<?php

namespace Database\Seeders\Demo;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DuplicateMatchStatus;
use App\Enums\FeedbackRecommendation;
use App\Enums\FollowupStatus;
use App\Enums\FollowupType;
use App\Enums\IncentiveCalculationStatus;
use App\Enums\InterviewMode;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Enums\OfferStatus;
use App\Enums\Priority;
use App\Enums\RecruitmentCostStatus;
use App\Enums\RecruitmentCostType;
use App\Enums\RequisitionStatus;
use App\Enums\TargetMetric;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateDocument;
use App\Models\CandidateDuplicateMatch;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\Offer;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentCost;
use App\Models\RecruitmentDailyActivity;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentManualActivity;
use App\Models\RecruitmentRequisition;
use App\Services\CandidateJoiningService;
use App\Services\EmployeeConversionService;
use App\Services\IncentiveApprovalService;
use App\Services\InterviewService;
use App\Services\OfferService;
use App\Services\PerformanceEngine;
use App\Services\RecruiterIncentiveCalculator;
use App\Services\RequisitionApprovalService;
use App\Services\StageTransitionService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Support\Facades\DB;

/**
 * Plays six months of recruitment through the product's own services, in chronological order (see
 * DemoTimeline): requisitions are raised, approved and opened; each candidate is sourced, called,
 * screened, interviewed, offered and onboarded — or drops out along the way, with a reason — and
 * the monthly incentive payroll, performance snapshots and recruitment costs run alongside. Journeys
 * still in progress today make up the live pipeline, the upcoming interviews and pending follow-ups.
 */
final class DemoRecruitmentStory
{
    /**
     * Relative daily call volume per recruiter, so the leaderboard has a spread.
     *
     * @var array<string, float>
     */
    private const array PACE = [
        'r_priya' => 1.12, 'r_arjun' => 1.0, 'r_sneha' => 0.95, 'r_rohit' => 1.05, 'r_pooja' => 0.82,
        'r_imran' => 0.78, 'r_karthik' => 1.08, 'r_divya' => 0.97, 'r_aman' => 0.85, 'r_lakshmi' => 1.02,
    ];

    /**
     * @var array<string, int>
     */
    private const array CALL_OUTCOMES = [
        'connected' => 40, 'no_answer' => 24, 'busy' => 10, 'switched_off' => 8, 'not_reachable' => 7,
        'call_back_later' => 7, 'invalid_number' => 2, 'wrong_number' => 1, 'not_interested' => 1,
    ];

    private readonly DemoTimeline $timeline;

    /**
     * @var array<string, RecruitmentRequisition>
     */
    private array $requisitions = [];

    public function __construct(
        private readonly DemoContext $ctx,
        private readonly StageTransitionService $stages,
        private readonly InterviewService $interviews,
        private readonly OfferService $offers,
        private readonly CandidateJoiningService $joinings,
        private readonly RequisitionApprovalService $requisitionApprovals,
        private readonly RecruiterIncentiveCalculator $calculator,
        private readonly IncentiveApprovalService $incentives,
        private readonly PerformanceEngine $performance,
        private readonly EmployeeConversionService $conversions,
    ) {
        $this->timeline = new DemoTimeline($ctx->today);
    }

    public static function for(DemoContext $ctx): self
    {
        return app()->make(self::class, ['ctx' => $ctx]);
    }

    public function play(): void
    {
        foreach (DemoCatalog::REQUISITIONS as $key => $spec) {
            $this->timeline->add($this->requisitionLifecycle($key, $spec));
        }

        $this->timeline->add($this->portalSubscriptions());
        $this->timeline->add($this->campaigns());
        $this->timeline->add($this->incentivePayroll());

        $this->timeline->run();
        $this->ctx->actAs(null);

        $this->recordBackgroundActivity();
        $this->recordManualActivities();
        $this->recordDuplicateCandidates();
        $this->recordPerformanceSnapshots();
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return Generator<int, CarbonInterface, mixed, void>
     */
    private function requisitionLifecycle(string $key, array $spec): Generator
    {
        $manager = $this->ctx->person($spec['manager']);
        $plan = $spec['plan'];
        $openedAt = $this->ctx->officeHours($this->ctx->today->subDays($spec['opened'])->setTime(10, $this->ctx->number(0, 59)));
        $raisedAt = $plan === 'draft' ? $openedAt : $this->ctx->officeHours($openedAt->subDays($this->ctx->number(3, 6)));

        yield $raisedAt;

        $this->ctx->actAs($manager);
        $requisition = RecruitmentRequisition::query()->create([
            'code' => $this->ctx->code('REQ'),
            'department_id' => $this->ctx->departments[$spec['department']]->id,
            'designation_id' => $this->ctx->designations[$spec['designation']]->id,
            'location_id' => $this->ctx->locations[$spec['location']]->id,
            'openings' => $spec['openings'],
            'employment_type' => $spec['type'],
            'salary_min' => $spec['salary'][0],
            'salary_max' => $spec['salary'][1],
            'experience_min' => $spec['experience'][0],
            'experience_max' => $spec['experience'][1],
            'qualification' => $spec['qualification'],
            'skills' => $spec['skills'],
            'shift' => $spec['shift'],
            'reporting_manager_id' => $this->ctx->person($spec['hiring_manager'])->id,
            'hiring_manager_id' => $this->ctx->person($spec['hiring_manager'])->id,
            'assistant_manager_id' => $this->ctx->person($spec['assistant_manager'])->id,
            'manager_id' => $manager->id,
            'vp_hr_id' => $this->ctx->person('vp_hr')->id,
            'priority' => $spec['priority'],
            'target_joining_date' => $openedAt->addDays(45)->toDateString(),
            'opening_date' => $openedAt->toDateString(),
            'closing_date' => $openedAt->addDays(75)->toDateString(),
            'remarks' => "Hiring {$spec['openings']} × {$this->ctx->designations[$spec['designation']]->name} for the {$this->ctx->locations[$spec['location']]->name}.",
            'status' => RequisitionStatus::Draft,
            'created_by' => $manager->id,
        ]);

        $requisition->recruiters()->attach(collect($spec['recruiters'])
            ->mapWithKeys(fn (string $recruiter): array => [$this->ctx->person($recruiter)->id => ['assigned_at' => $this->ctx->now()]])
            ->all());

        $this->requisitions[$key] = $requisition;

        if ($plan === 'draft') {
            return;
        }

        yield $this->ctx->later($this->ctx->now(), 60, 8 * 60);

        $this->ctx->actAs($manager);
        $this->requisitionApprovals->submitForApproval($requisition, $manager, 'Headcount approved in the quarterly manpower plan.');

        if ($plan === 'pending_approval') {
            return;
        }

        yield $this->ctx->later($this->ctx->now(), 3 * 60, 26 * 60);

        $vpHr = $this->ctx->person('vp_hr');
        $this->ctx->actAs($vpHr);
        $this->requisitionApprovals->approve($requisition, $vpHr, 'Budget and salary band verified.');

        if ($plan === 'approved') {
            return;
        }

        yield $openedAt;

        $this->ctx->actAs($manager);
        $this->requisitionApprovals->open($requisition, $manager, 'Sourcing started.');

        $windowEnd = match ($plan) {
            'closed' => $this->ctx->today->subDays($spec['plan_days'] + 12),
            'on_hold', 'cancelled' => $this->ctx->today->subDays($spec['plan_days']),
            default => $this->ctx->today,
        };

        $windowStart = $this->ctx->now()->addHours(3);
        $span = max(60, (int) abs($windowStart->diffInMinutes($windowEnd)));

        for ($i = 0, $count = $this->ctx->scaled($spec['openings'] * $spec['apps']); $i < $count; $i++) {
            // Mildly weighted towards recent weeks, as sourcing picks up once a role is established.
            $arrival = $this->ctx->officeHours($windowStart->addMinutes((int) round($span * $this->ctx->faker->randomFloat(6, 0, 1) ** 0.77)));
            $recruiter = $this->ctx->person($this->ctx->pick($spec['recruiters']));

            $this->timeline->add($this->journey($requisition, $spec, $recruiter, $arrival));
        }

        if (! in_array($plan, ['on_hold', 'closed', 'cancelled'], true)) {
            return;
        }

        yield $this->ctx->officeHours($this->ctx->today->subDays($spec['plan_days'])->setTime($plan === 'closed' ? 17 : 12, 0));

        if ($this->statusOf($requisition) !== RequisitionStatus::Open) {
            return;
        }

        $this->ctx->actAs($manager);

        match ($plan) {
            'on_hold' => $this->requisitionApprovals->hold($requisition, $manager, 'Budget review — hiring paused until the next quarter.'),
            'cancelled' => $this->requisitionApprovals->cancel($requisition, $manager, 'Requirement merged into the Digital Marketing team; no separate hire this quarter.'),
            default => $this->requisitionApprovals->close($requisition, $manager, $this->joinedCount($requisition) >= $requisition->openings
                ? 'All openings filled.'
                : 'Closed by the hiring manager — role deferred to the next quarter.'),
        };
    }

    /**
     * One candidate's path through the pipeline, from sourcing to onboarding — or to wherever it
     * ends, with a reason.
     *
     * @param  array<string, mixed>  $spec
     * @return Generator<int, CarbonInterface, mixed, void>
     */
    private function journey(RecruitmentRequisition $requisition, array $spec, Employee $recruiter, CarbonImmutable $arrival): Generator
    {
        yield $arrival;

        if ($this->statusOf($requisition) !== RequisitionStatus::Open) {
            return;
        }

        $this->ctx->actAs($recruiter);
        $candidate = $this->createCandidate($spec, $recruiter);

        $application = CandidateApplication::query()->create([
            'application_code' => $this->ctx->code('APP'),
            'candidate_id' => $candidate->id,
            'requisition_id' => $requisition->id,
            'recruiter_id' => $recruiter->id,
            'current_stage' => CandidateStage::Sourced,
            'application_date' => $this->ctx->now()->toDateString(),
            'priority' => $this->priorityFor($spec['priority']),
            'last_activity_at' => $this->ctx->now(),
            'status' => ApplicationStatus::Active,
        ]);
        $application->setRelation('candidate', $candidate);

        // First contact: up to three call attempts.
        $nextAttempt = $this->ctx->later($this->ctx->now(), 30, 26 * 60);
        $followUp = null;
        $connected = false;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            yield $nextAttempt;

            if (! $this->stillHiring($requisition, $application, $recruiter)) {
                return;
            }

            if ($followUp !== null) {
                $this->closeFollowUp($followUp, $application, 'Called as planned.');
            }

            if ($attempt === 1) {
                $this->stages->transitionTo($application, CandidateStage::ContactAttempted, $recruiter);
            }

            $connected = $this->ctx->chance($attempt === 1 ? 0.62 : 0.5);
            $this->activity($application, $recruiter, ActivityType::Call, $connected ? ActivityOutcome::Connected : $this->missedCallOutcome(), $connected ? 'Introduced the role and the company.' : null);

            if ($connected || $attempt === 3) {
                break;
            }

            $nextAttempt = $this->ctx->later($this->ctx->now(), 18 * 60, 30 * 60);
            $followUp = $this->followUp($application, $recruiter, FollowupType::Call, $nextAttempt, 'Call again — could not reach the candidate.');
        }

        if (! $connected) {
            $this->dropout($application, $recruiter, 'No Response', 'Not reachable after three call attempts.');

            return;
        }

        $this->stages->transitionTo($application, CandidateStage::Connected, $recruiter);

        // Many candidates ask for a day to think it over before committing.
        if ($this->ctx->chance(0.35)) {
            $decisionAt = $this->ctx->later($this->ctx->now(), 18 * 60, 40 * 60);
            $followUp = $this->followUp($application, $recruiter, FollowupType::Call, $decisionAt, 'Candidate asked for a day to think it over.');

            yield $decisionAt;

            if (! $this->stillHiring($requisition, $application, $recruiter)) {
                return;
            }

            $this->closeFollowUp($followUp, $application, 'Called back for a decision.');
            $this->activity($application, $recruiter, ActivityType::Call, ActivityOutcome::Connected, 'Called back for the candidate\'s decision.');
        }

        if (! $this->ctx->chance(0.72)) {
            $reason = $this->ctx->weighted(['Not Interested' => 45, 'Salary Issue' => 25, 'Location Issue' => 20, 'Notice Period' => 10]);
            $this->dropout($application, $recruiter, $reason, $this->dropoutRemark($reason));

            return;
        }

        $this->stages->transitionTo($application, CandidateStage::Interested, $recruiter, 'Interested in the role.');
        $this->activity($application, $recruiter, ActivityType::WhatsApp, null, 'Shared the job description and company profile on WhatsApp.');

        if (yield from $this->stall($application, $recruiter, 'Collect the updated CV and screening answers.')) {
            return;
        }

        yield $this->ctx->later($this->ctx->now(), 20 * 60, 72 * 60);

        if (! $this->stillHiring($requisition, $application, $recruiter)) {
            return;
        }

        if (! $this->ctx->chance(0.85)) {
            $this->reject($application, $recruiter, $this->ctx->pick(['Experience Mismatch', 'Qualification Mismatch']), 'Did not clear telephonic screening.');

            return;
        }

        $this->stages->transitionTo($application, CandidateStage::Screened, $recruiter, 'Telephonic screening completed.');

        yield $this->ctx->later($this->ctx->now(), 20 * 60, 72 * 60);

        if (! $this->stillHiring($requisition, $application, $recruiter)) {
            return;
        }

        if ($this->ctx->chance(0.03)) {
            $this->stages->hold($application, $recruiter, 'Candidate asked us to reconnect next month.');

            return;
        }

        if (! $this->ctx->chance(0.8)) {
            $this->reject($application, $recruiter, 'Other', 'Profile not shortlisted by the hiring manager.');

            return;
        }

        $hiringManager = $this->ctx->person($spec['hiring_manager']);
        $this->stages->transitionTo($application, CandidateStage::Shortlisted, $recruiter, "Profile shortlisted by {$hiringManager->fullName()}.");

        if (yield from $this->stall($application, $recruiter, 'Share interview slots with the candidate.')) {
            return;
        }

        $lastInterview = null;

        foreach ($spec['rounds'] as $index => $round) {
            $lastInterview = yield from $this->interviewRound($requisition, $application, $recruiter, $index + 1, $round);

            if ($lastInterview === null) {
                return;
            }
        }

        if ($lastInterview === null) {
            return;
        }

        yield $this->ctx->later($this->ctx->now(), 2 * 60, 36 * 60);

        if (! $this->stillHiring($requisition, $application, $recruiter)) {
            return;
        }

        if ($this->openingsLeft($requisition) <= 0) {
            $this->stages->hold($application, $recruiter, 'All openings are committed — kept as a backup candidate.');

            return;
        }

        $this->interviews->selectCandidate($lastInterview, $recruiter);
        $this->calculator->calculateForSelection($application);
        $this->activity($application, $recruiter, ActivityType::Call, ActivityOutcome::Connected, 'Shared the selection news and discussed compensation.');

        $offer = yield from $this->offerStage($requisition, $spec, $application, $recruiter);

        if ($offer === null) {
            return;
        }

        yield from $this->joiningStage($requisition, $spec, $application, $recruiter);
    }

    /**
     * @param  array{0: string|null, 1: string, 2: string}  $round
     * @return Generator<int, CarbonInterface, mixed, Interview|null>
     */
    private function interviewRound(RecruitmentRequisition $requisition, CandidateApplication $application, Employee $recruiter, int $roundNumber, array $round): Generator
    {
        [$roundName, $interviewerKey, $mode] = $round;
        $interviewer = $this->ctx->person($interviewerKey);
        $mode = InterviewMode::from($mode);

        yield $this->ctx->later($this->ctx->now(), 3 * 60, 40 * 60);

        if (! $this->stillHiring($requisition, $application, $recruiter)) {
            return null;
        }

        $scheduledAt = $this->ctx->slot($this->ctx->now()->addDays($this->ctx->number(1, 4)));

        $interview = $this->interviews->schedule($application, [
            'round_number' => $roundNumber,
            'round_name' => $roundName,
            'interviewer_id' => $interviewer->id,
            'scheduled_at' => $scheduledAt,
            'mode' => $mode,
            'location' => $mode === InterviewMode::InPerson ? $requisition->location?->name.', Meeting Room '.$this->ctx->pick(['A', 'B', 'C', 'D']) : null,
            'meeting_link' => $mode === InterviewMode::VideoCall ? 'https://meet.google.com/'.$this->ctx->faker->lexify('???-????-???') : null,
        ], $recruiter);

        $this->activity($application, $recruiter, ActivityType::WhatsApp, null, "Interview invite sent for {$scheduledAt->format('d M, h:i A')}.");

        $confirmAt = $scheduledAt->subDay()->setTime($this->ctx->number(10, 17), $this->ctx->number(0, 59));

        if ($confirmAt->lessThanOrEqualTo($this->ctx->now())) {
            $confirmAt = $this->ctx->now()->addMinutes(45);
        }

        $followUp = $this->followUp($application, $recruiter, FollowupType::InterviewConfirmation, $confirmAt, "Confirm the round {$roundNumber} interview.");

        yield $confirmAt;

        $this->ctx->actAs($recruiter);

        if ($this->ctx->chance(0.07)) {
            $scheduledAt = $this->ctx->slot($scheduledAt->addDays($this->ctx->number(1, 3)));
            $this->interviews->reschedule($interview, $scheduledAt, 'Candidate requested a later slot.', $recruiter);
            $this->closeFollowUp($followUp, $application, 'Candidate asked to reschedule.');
        } else {
            $this->closeFollowUp($followUp, $application, 'Candidate confirmed attendance.');
        }

        if ($this->ctx->chance(0.85)) {
            $this->interviews->confirm($interview);
            $this->activity($application, $recruiter, ActivityType::Call, ActivityOutcome::Connected, 'Interview confirmed with the candidate.');
        }

        yield $scheduledAt->addMinutes($this->ctx->number(45, 180));

        $this->ctx->actAs($recruiter);

        if ($this->ctx->chance(0.06)) {
            $interview->forceFill(['status' => InterviewStatus::NoShow])->save();
            $this->dropout($application, $recruiter, 'No Response', "Did not turn up for interview round {$roundNumber}.");

            return null;
        }

        // Feedback never submitted: stays in the Action Center as pending feedback.
        if ($this->ctx->chance(0.04)) {
            return null;
        }

        $passed = $this->ctx->chance([1 => 0.66, 2 => 0.7][$roundNumber] ?? 0.8);
        $rating = fn (): int => $passed ? $this->ctx->number(3, 5) : $this->ctx->number(1, 3);

        InterviewFeedback::query()->create([
            'interview_id' => $interview->id,
            'interviewer_id' => $interviewer->id,
            'ratings' => ['technical' => $rating(), 'communication' => $rating(), 'problem_solving' => $rating(), 'culture_fit' => $rating()],
            'recommendation' => $passed
                ? ($this->ctx->chance(0.35) ? FeedbackRecommendation::StronglyRecommend : FeedbackRecommendation::Recommend)
                : ($this->ctx->chance(0.25) ? FeedbackRecommendation::Neutral : FeedbackRecommendation::NotRecommend),
            'feedback' => $this->ctx->pick($passed ? DemoCatalog::POSITIVE_FEEDBACK : DemoCatalog::NEGATIVE_FEEDBACK),
        ]);

        $this->interviews->complete(
            $interview,
            $passed ? InterviewResult::Selected : InterviewResult::Rejected,
            $recruiter,
            $passed ? null : $this->ctx->reason('Interview Rejected'),
        );

        return $passed ? $interview : null;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return Generator<int, CarbonInterface, mixed, Offer|null>
     */
    private function offerStage(RecruitmentRequisition $requisition, array $spec, CandidateApplication $application, Employee $recruiter): Generator
    {
        yield $this->ctx->later($this->ctx->now(), 3 * 60, 40 * 60);

        $this->ctx->actAs($recruiter);

        $ctc = $this->ctx->amount($spec['salary'][0], $spec['salary'][1]);
        $variable = (int) round($ctc * match ($spec['department']) {
            'SAL' => 0.15,
            'TECH' => 0.1,
            default => 0.0,
        } / 1000) * 1000;
        $offerDate = $this->ctx->now();

        $offer = $this->offers->create([
            'offer_code' => $this->ctx->code('OFR'),
            'candidate_application_id' => $application->id,
            'designation_id' => $requisition->designation_id,
            'location_id' => $requisition->location_id,
            'offered_ctc' => $ctc,
            'fixed_salary' => $ctc - $variable,
            'variable_salary' => $variable,
            'joining_bonus' => $spec['department'] === 'TECH' && $this->ctx->chance(0.3) ? $this->ctx->pick([25000, 50000]) : null,
            'offer_date' => $offerDate->toDateString(),
            'offer_expiry' => $offerDate->addDays(7)->toDateString(),
            'expected_joining_date' => $this->weekday($offerDate->addDays($this->ctx->number(15, 30)))->toDateString(),
            'remarks' => 'Within the approved salary band.',
            'created_by' => $recruiter->id,
        ], $recruiter);

        yield $this->ctx->later($this->ctx->now(), 30, 5 * 60);

        $this->ctx->actAs($recruiter);
        $this->offers->moveTo($offer, OfferStatus::Initiated, $recruiter, 'Sent to the recruitment manager for release.');

        yield $this->ctx->later($this->ctx->now(), 3 * 60, 30 * 60);

        $manager = $this->ctx->person($spec['manager']);
        $this->ctx->actAs($manager);

        if ($this->ctx->chance(0.03)) {
            $this->offers->moveTo($offer, OfferStatus::Withdrawn, $manager, 'Withdrawn pending budget re-approval.');
            $this->stages->hold($application, $manager, 'Offer withdrawn pending budget re-approval.');

            return null;
        }

        $this->offers->moveTo($offer, OfferStatus::Released, $manager, 'Released within the approved band.');

        $this->ctx->actAs($recruiter);
        $this->activity($application, $recruiter, ActivityType::Email, null, 'Offer letter emailed to the candidate.');
        $decisionAt = $this->ctx->later($this->ctx->now(), 20 * 60, 96 * 60);
        $followUp = $this->followUp($application, $recruiter, FollowupType::OfferFollowup, $decisionAt, 'Follow up on the offer decision.');

        yield $decisionAt;

        $this->ctx->actAs($recruiter);
        $decision = $this->ctx->weighted(['accepted' => 80, 'rejected' => 13, 'silent' => 7]);

        if ($decision === 'accepted') {
            $this->closeFollowUp($followUp, $application, 'Candidate accepted the offer.');
            $this->offers->moveTo($offer, OfferStatus::Accepted, $recruiter, 'Candidate accepted over email.');
            $this->calculator->calculateForOfferAcceptance($application);

            return $offer;
        }

        if ($decision === 'rejected') {
            $this->closeFollowUp($followUp, $application, 'Candidate declined the offer.');
            $this->offers->moveTo($offer, OfferStatus::Rejected, $recruiter, 'Accepted a counter-offer from the current employer.', $this->ctx->reason('Offer Rejected'));

            return null;
        }

        $this->closeFollowUp($followUp, $application, 'No response from the candidate.', FollowupStatus::Missed);

        yield $this->ctx->officeHours(CarbonImmutable::instance($offer->offer_expiry)->addDay()->setTime(10, 0));

        if ($offer->refresh()->status === OfferStatus::Released) {
            $this->ctx->actAs(null);
            $this->offers->moveTo($offer, OfferStatus::Expired, null, 'Offer validity lapsed');
            $this->ctx->actAs($recruiter);
            $this->dropout($application, $recruiter, 'No Response', 'Did not respond to the offer before it expired.');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return Generator<int, CarbonInterface, mixed, void>
     */
    private function joiningStage(RecruitmentRequisition $requisition, array $spec, CandidateApplication $application, Employee $recruiter): Generator
    {
        $joining = CandidateJoining::query()->where('candidate_application_id', $application->id)->firstOrFail();
        $documents = $this->requestDocuments($joining, $application);
        $joiningDay = CarbonImmutable::instance($joining->expected_doj)->setTime(10, 0);
        $confirmAt = $this->ctx->officeHours($joiningDay->subDays($this->ctx->number(2, 4))->setTime($this->ctx->number(11, 16), $this->ctx->number(0, 59)));
        $followUp = $this->followUp($application, $recruiter, FollowupType::JoiningConfirmation, $confirmAt, 'Confirm the joining date and documents.');

        if ($this->ctx->chance(0.06)) {
            yield $this->ctx->officeHours($joiningDay->subDays($this->ctx->number(5, 8)));

            $this->ctx->actAs($recruiter);
            $this->closeFollowUp($followUp, $application, 'Candidate backed out before joining.');
            $this->joinings->markDropout($joining, $this->ctx->reason($this->ctx->pick(['Selected Elsewhere', 'Background Verification'])), $recruiter);

            return;
        }

        yield $confirmAt;

        $this->ctx->actAs($recruiter);
        $this->closeFollowUp($followUp, $application, 'Joining date reconfirmed on call.');

        if ($this->ctx->chance(0.9)) {
            $this->joinings->confirm($joining, $recruiter);
            $this->updateDocuments($documents, DocumentStatus::Submitted);
            $this->activity($application, $recruiter, ActivityType::Call, ActivityOutcome::Connected, 'Candidate confirmed the joining date.');
        }

        yield $joiningDay->addMinutes($this->ctx->number(0, 90));

        $this->ctx->actAs($recruiter);

        if ($this->ctx->chance(0.1)) {
            $this->joinings->markNoShow($joining, $this->ctx->reason('Did Not Join'), $recruiter);

            return;
        }

        $this->joinings->markJoined($joining, now(), $recruiter);
        $this->afterJoining($requisition, $spec, $application);

        yield $this->ctx->later($this->ctx->now(), 2 * 1440, 6 * 1440);

        $this->ctx->actAs($recruiter);
        $this->updateDocuments($documents, DocumentStatus::Verified, $this->ctx->person('hrbp'));
        $this->joinings->markDocumentsCompleted($joining, $recruiter);

        yield $this->ctx->later($this->ctx->now(), 3 * 1440, 10 * 1440);

        $this->ctx->actAs($recruiter);
        $this->joinings->markOnboardingCompleted($joining, $recruiter);

        yield $this->ctx->later($this->ctx->now(), 1440, 3 * 1440);

        if ($this->ctx->chance(0.75)) {
            $this->ctx->actAs($this->ctx->person('chro'));
            $this->conversions->convert($joining->refresh());
        }
    }

    /**
     * Agency fees and referral payouts, and closing the requisition once every opening has joined.
     *
     * @param  array<string, mixed>  $spec
     */
    private function afterJoining(RecruitmentRequisition $requisition, array $spec, CandidateApplication $application): void
    {
        $candidate = $application->candidate;
        $source = $candidate->source?->name;

        if ($source === 'Agency') {
            $ctc = (float) $application->offers()->where('status', OfferStatus::Accepted)->value('offered_ctc');
            $this->cost(RecruitmentCostType::Agency, (int) round($ctc * 0.0833), 'Agency', $requisition, null, "Agency fee (8.33% of CTC) — {$candidate->source_details} placed {$candidate->full_name}.");
        }

        if ($source === 'Employee Referral') {
            $this->timeline->add($this->referralPayout($application, $this->ctx->now()->addDays(30)));
        }

        if ($this->joinedCount($requisition) >= $requisition->openings && $this->statusOf($requisition) === RequisitionStatus::Open) {
            $manager = $this->ctx->person($spec['manager']);
            $this->ctx->actAs($manager);
            $this->requisitionApprovals->close($requisition, $manager, "All {$requisition->openings} openings filled.");
        }
    }

    /**
     * @return Generator<int, CarbonInterface, mixed, void>
     */
    private function referralPayout(CandidateApplication $application, CarbonImmutable $at): Generator
    {
        yield $this->ctx->officeHours($at);

        $candidate = $application->candidate;
        $this->ctx->actAs($this->ctx->person('vp_hr'));
        $this->cost(RecruitmentCostType::ReferralPayout, 7500, 'Employee Referral', $application->requisition, null, "Referral bonus — {$candidate->full_name}, referred by {$candidate->referralEmployee?->fullName()}.");
    }

    /**
     * @return Generator<int, CarbonInterface, mixed, void>
     */
    private function portalSubscriptions(): Generator
    {
        for ($month = $this->ctx->storyStart->startOfMonth(); $month->lessThanOrEqualTo($this->ctx->today); $month = $month->addMonthNoOverflow()) {
            yield $month->addDay()->setTime(12, 0);

            $this->ctx->actAs($this->ctx->person('vp_hr'));
            $this->cost(RecruitmentCostType::JobPortal, 42000, 'Naukri', null, null, 'Naukri Resdex and job-posting subscription.');
            $this->cost(RecruitmentCostType::JobPortal, 9500, 'Apna', null, null, 'Apna premium job posts for frontline roles.');
            $this->cost(RecruitmentCostType::JobPortal, 12000, 'Indeed', null, null, 'Indeed sponsored jobs.');

            if ($month->month % 3 === 1) {
                $this->cost(RecruitmentCostType::JobPortal, 68000, 'LinkedIn', null, null, 'LinkedIn Recruiter seat — quarterly.');
            }
        }
    }

    /**
     * @return Generator<int, CarbonInterface, mixed, void>
     */
    private function campaigns(): Generator
    {
        $campaigns = [
            [125, 'se_mum', RecruitmentCostType::Campaign, 28000, 'Walk-in', 'Mumbai Sales Walk-in Drive', 'Venue, standees and a newspaper insert for the walk-in drive.'],
            [95, 'csa_blr', RecruitmentCostType::Advertising, 22000, 'Facebook', 'Support Hiring — Meta Lead Ads', 'Meta lead-generation ads for voice support roles.'],
            [80, 'sld_blr', RecruitmentCostType::Advertising, 18000, 'LinkedIn', 'Laravel Developers — LinkedIn Job Slots', 'Promoted job slots for senior developer roles.'],
            [50, 'da_blr', RecruitmentCostType::Campaign, 15000, 'WhatsApp', 'Delivery Partner WhatsApp Campaign', 'Broadcast to local job groups for delivery associates.'],
            [20, 'tc_kol', RecruitmentCostType::Advertising, 12000, 'Instagram', 'Kolkata Telecaller Reels', 'Instagram reels promoting telecaller openings.'],
        ];

        foreach ($campaigns as [$daysAgo, $requisition, $type, $amount, $source, $campaign, $remarks]) {
            yield $this->ctx->today->subDays($daysAgo)->setTime(12, 30);

            if (isset($this->requisitions[$requisition])) {
                $this->ctx->actAs($this->ctx->person('vp_hr'));
                $this->cost($type, $amount, $source, $this->requisitions[$requisition], $campaign, $remarks);
            }
        }
    }

    /**
     * The monthly incentive payroll: verify on the 5th, mark payable on the 12th, pay on the 25th.
     *
     * @return Generator<int, CarbonInterface, mixed, void>
     */
    private function incentivePayroll(): Generator
    {
        $vpHr = $this->ctx->person('vp_hr');
        $chro = $this->ctx->person('chro');
        $adjusted = false;
        $reversed = false;

        for ($month = $this->ctx->storyStart->startOfMonth()->addMonthNoOverflow(); $month->lessThanOrEqualTo($this->ctx->today); $month = $month->addMonthNoOverflow()) {
            yield $month->addDays(4)->setTime(11, 0);

            $this->ctx->actAs($vpHr);
            $this->incentives->releaseMatured();

            foreach ($this->calculationsBefore($month, IncentiveCalculationStatus::PendingVerification) as $calculation) {
                if ($this->ctx->chance(0.05)) {
                    $this->incentives->reject($calculation, 'Not eligible — the candidate was placed by an agency.', $vpHr);

                    continue;
                }

                $this->incentives->approve($calculation, $vpHr, 'Verified against joining records.');

                if (! $adjusted) {
                    $this->incentives->adjust($calculation, 500, 'Top-up approved for a hard-to-fill role.', $vpHr);
                    $adjusted = true;
                }
            }

            yield $month->addDays(11)->setTime(15, 0);

            $this->ctx->actAs($chro);

            foreach ($this->calculationsBefore($month, IncentiveCalculationStatus::Approved) as $calculation) {
                $this->incentives->markPayable($calculation, $chro, "Added to the {$month->format('F')} payroll run.");
            }

            yield $month->addDays(24)->setTime(12, 0);

            $this->ctx->actAs($chro);

            foreach ($this->calculationsBefore($month, IncentiveCalculationStatus::Payable) as $calculation) {
                $this->incentives->pay($calculation, $calculation->effectiveAmount(), $this->ctx->now(), 'PAY/'.$month->format('Ym').'/'.str_pad((string) $calculation->id, 4, '0', STR_PAD_LEFT), $chro, "Paid with the {$month->format('F')} salary.");

                if (! $reversed && $month->greaterThan($this->ctx->storyStart->addMonths(3))) {
                    $this->incentives->reverse($calculation->refresh(), 'Joinee exited within 30 days — incentive recovered in the next payroll.', $chro);
                    $reversed = true;
                }
            }
        }
    }

    /**
     * @return iterable<int, RecruiterIncentiveCalculation>
     */
    private function calculationsBefore(CarbonImmutable $month, IncentiveCalculationStatus $status): iterable
    {
        return RecruiterIncentiveCalculation::query()
            ->where('status', $status)
            ->whereDate('period_end', '<', $month->toDateString())
            ->orderBy('id')
            ->get();
    }

    /**
     * Month-end performance snapshots for the completed months once hiring was in full swing (the
     * first two months, with only a handful of requisitions open, are left out), taken once all the
     * activity above exists and dated just after each month closed, as the nightly job would have.
     * The current month is snapshotted by `performance:snapshot` at the end of seeding.
     */
    private function recordPerformanceSnapshots(): void
    {
        $first = $this->ctx->storyStart->addMonthsNoOverflow(2)->startOfMonth();

        for ($month = $first; $month->endOfMonth()->lessThan($this->ctx->today); $month = $month->addMonthNoOverflow()) {
            DemoContext::freeze($month->endOfMonth()->addDay()->setTime(0, 30));
            $this->performance->snapshotAllRecruiters($month, $month->endOfMonth());
        }

        DemoContext::freeze(null);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function createCandidate(array $spec, Employee $recruiter): Candidate
    {
        $name = $this->ctx->personName();
        $experience = round($this->ctx->faker->randomFloat(1, $spec['experience'][0], $spec['experience'][1] + 1), 1);
        $fresher = $experience < 0.5;
        $currentSalary = $fresher ? null : $this->ctx->amount($spec['salary'][0] * 0.7, $spec['salary'][1] * 0.9);
        $source = $this->ctx->weighted($this->sourceWeightsFor($spec));
        $referrer = $source === 'Employee Referral'
            ? $this->ctx->person($this->ctx->pick(['sales_tl', 'cs_tl', 'tech_lead', 'hrbp', 'sales_head', 'ops_head']))
            : null;
        $city = $this->ctx->chance(0.82)
            ? DemoCatalog::LOCATIONS[$spec['location']]['city']
            : $this->ctx->pick(array_column(DemoCatalog::LOCATIONS, 'city'));

        $candidate = Candidate::query()->create([
            'candidate_code' => $this->ctx->code('CAND'),
            'full_name' => $name,
            'mobile' => $this->ctx->mobile(),
            'alternate_mobile' => $this->ctx->chance(0.15) ? $this->ctx->mobile() : null,
            'email' => $this->ctx->chance(0.8) ? $this->ctx->email($name) : null,
            'location' => $city,
            'current_city' => $city,
            'qualification' => $this->qualificationFor($spec),
            'total_experience' => $experience,
            'relevant_experience' => $fresher ? 0 : round($experience * $this->ctx->faker->randomFloat(2, 0.5, 1), 1),
            'current_company' => $fresher ? null : $this->ctx->pick(DemoCatalog::COMPANIES),
            'current_designation' => $fresher ? null : $this->currentDesignationFor($spec['department']),
            'current_salary' => $currentSalary,
            'expected_salary' => $currentSalary !== null
                ? (int) (round($currentSalary * $this->ctx->faker->randomFloat(2, 1.15, 1.35) / 1000) * 1000)
                : $this->ctx->amount($spec['salary'][0], $spec['salary'][1]),
            'notice_period_days' => $fresher ? 0 : $this->ctx->pick([0, 15, 30, 30, 45, 60, 90]),
            'skills' => $this->ctx->faker->randomElements($spec['skills'], min(count($spec['skills']), $this->ctx->number(2, 4))),
            'source_id' => $this->ctx->source($source)->id,
            'source_details' => match ($source) {
                'Naukri' => 'Resdex search — '.$spec['skills'][0],
                'LinkedIn' => 'LinkedIn Recruiter InMail',
                'Employee Referral' => 'Referred by '.$referrer?->fullName(),
                'Walk-in' => 'Walk-in at the '.DemoCatalog::LOCATIONS[$spec['location']]['name'],
                'Agency' => $this->ctx->pick(['TalentBridge Consultants', 'HireRight Partners']),
                'Apna' => 'Apna job post',
                'WhatsApp' => 'WhatsApp job broadcast',
                default => null,
            },
            'referral_employee_id' => $referrer?->id,
            'created_by' => $recruiter->id,
        ]);

        $this->ctx->candidatesByRecruiter[$recruiter->id][] = [$candidate->id, $this->ctx->now()->getTimestamp()];

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, int>
     */
    private function sourceWeightsFor(array $spec): array
    {
        $weights = DemoCatalog::SOURCE_WEIGHTS;

        if ($spec['department'] === 'TECH') {
            $weights = [...$weights, 'LinkedIn' => 26, 'Naukri' => 28, 'Agency' => 10, 'Apna' => 0, 'WorkIndia' => 0, 'Walk-in' => 0];
        } elseif ($spec['openings'] >= 10) {
            $weights = [...$weights, 'Apna' => 22, 'WorkIndia' => 14, 'Walk-in' => 12, 'LinkedIn' => 2, 'Agency' => 0];
        }

        return array_filter($weights);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function qualificationFor(array $spec): string
    {
        if (str_starts_with($spec['qualification'], 'Graduate (any stream)')) {
            return $this->ctx->pick(['B.Com', 'B.A.', 'B.Sc', 'BBA', 'BCA']);
        }

        return trim((string) $this->ctx->pick(preg_split('/\s*\/\s*/', $spec['qualification']) ?: [$spec['qualification']]));
    }

    private function currentDesignationFor(string $department): string
    {
        return $this->ctx->pick(match ($department) {
            'SAL' => ['Sales Executive', 'Business Development Executive', 'Sales Officer', 'Relationship Manager'],
            'OPS' => ['Operations Executive', 'Delivery Executive', 'Warehouse Associate', 'Logistics Coordinator'],
            'CS' => ['Customer Care Executive', 'Process Associate', 'Support Executive', 'Senior Associate'],
            'TECH' => ['Software Engineer', 'Senior Software Engineer', 'Web Developer', 'Analyst'],
            'FIN' => ['Accounts Executive', 'Accountant'],
            'MKT' => ['Marketing Executive', 'Digital Marketing Associate'],
            'RTL' => ['Store Executive', 'Sales Associate', 'Assistant Store Manager'],
            default => ['HR Associate', 'HR Executive'],
        });
    }

    private function priorityFor(string $requisitionPriority): Priority
    {
        return Priority::from($this->ctx->weighted(match ($requisitionPriority) {
            'urgent' => ['urgent' => 45, 'high' => 35, 'medium' => 20],
            'high' => ['high' => 50, 'medium' => 40, 'urgent' => 10],
            'medium' => ['medium' => 70, 'high' => 15, 'low' => 15],
            default => ['low' => 60, 'medium' => 40],
        }));
    }

    private function missedCallOutcome(): ActivityOutcome
    {
        return ActivityOutcome::from($this->ctx->weighted(['no_answer' => 40, 'busy' => 20, 'switched_off' => 15, 'not_reachable' => 15, 'call_back_later' => 10]));
    }

    private function dropoutRemark(string $reason): string
    {
        return match ($reason) {
            'Not Interested' => 'Not looking for a change right now.',
            'Salary Issue' => 'Expected salary is well above the approved band.',
            'Location Issue' => 'Not willing to relocate or commute to the office.',
            default => 'Serving a long notice period; cannot join in time.',
        };
    }

    private function activity(CandidateApplication $application, Employee $recruiter, ActivityType $type, ?ActivityOutcome $outcome, ?string $remarks): void
    {
        RecruitmentDailyActivity::query()->create([
            'recruiter_id' => $recruiter->id,
            'candidate_id' => $application->candidate_id,
            'candidate_application_id' => $application->id,
            'activity_type' => $type,
            'activity_datetime' => $this->ctx->now(),
            'outcome' => $outcome,
            'remarks' => $remarks,
            'created_by' => $recruiter->id,
        ]);
    }

    private function followUp(CandidateApplication $application, Employee $recruiter, FollowupType $type, CarbonInterface $due, string $remarks): RecruitmentFollowup
    {
        $followUp = RecruitmentFollowup::query()->create([
            'candidate_application_id' => $application->id,
            'recruiter_id' => $recruiter->id,
            'followup_type' => $type,
            'followup_date' => $due,
            'status' => FollowupStatus::Pending,
            'remarks' => $remarks,
            'created_by' => $recruiter->id,
        ]);

        $application->forceFill(['next_followup_at' => $due])->save();

        return $followUp;
    }

    private function closeFollowUp(RecruitmentFollowup $followUp, CandidateApplication $application, string $outcome, FollowupStatus $status = FollowupStatus::Completed): void
    {
        $followUp->update(['status' => $status, 'outcome' => $outcome]);
        $application->forceFill(['next_followup_at' => null])->save();
    }

    /**
     * Some candidates simply stall: the journey stops with a pending follow-up. A recent one is
     * still pending (or overdue) today — the "stuck candidates" the Action Center surfaces; an old
     * one was eventually written off as missed, with the candidate dropped for no response.
     *
     * @return Generator<int, CarbonInterface, mixed, bool>
     */
    private function stall(CandidateApplication $application, Employee $recruiter, string $task): Generator
    {
        if (! $this->ctx->chance(0.06)) {
            return false;
        }

        $due = $this->ctx->later($this->ctx->now(), 20 * 60, 72 * 60);
        $followUp = $this->followUp($application, $recruiter, FollowupType::Call, $due, $task);

        yield $this->ctx->officeHours($due->addDays($this->ctx->number(5, 12)));

        $this->ctx->actAs($recruiter);
        $this->closeFollowUp($followUp, $application, 'Candidate stopped responding.', FollowupStatus::Missed);

        if ($application->status === ApplicationStatus::Active) {
            $this->dropout($application, $recruiter, 'No Response', 'Stopped responding after the last follow-up.');
        }

        return true;
    }

    private function dropout(CandidateApplication $application, Employee $actor, string $reason, string $remarks): void
    {
        $this->stages->dropout($application, $this->ctx->reason($reason), $actor, $remarks);
        $this->cancelPendingFollowUps($application);
    }

    private function reject(CandidateApplication $application, Employee $actor, string $reason, string $remarks): void
    {
        $this->stages->reject($application, $this->ctx->reason($reason), $actor, $remarks);
        $this->cancelPendingFollowUps($application);
    }

    private function cancelPendingFollowUps(CandidateApplication $application): void
    {
        $application->followups()->where('status', FollowupStatus::Pending)->update(['status' => FollowupStatus::Cancelled, 'outcome' => 'Application closed.']);
        $application->forceFill(['next_followup_at' => null])->save();
    }

    /**
     * Whether the requisition is still hiring. On hold pauses the journey; once it's closed or
     * cancelled the candidate is released with a reason.
     */
    private function stillHiring(RecruitmentRequisition $requisition, CandidateApplication $application, Employee $recruiter): bool
    {
        $this->ctx->actAs($recruiter);
        $status = $this->statusOf($requisition);

        if ($status === RequisitionStatus::Open) {
            return true;
        }

        if (in_array($status, [RequisitionStatus::Closed, RequisitionStatus::Cancelled], true) && $application->status === ApplicationStatus::Active) {
            $this->reject($application, $recruiter, 'Other', 'Requisition closed before the candidate progressed.');
        }

        return false;
    }

    private function statusOf(RecruitmentRequisition $requisition): RequisitionStatus
    {
        $status = RecruitmentRequisition::query()->whereKey($requisition->id)->value('status');

        return $status instanceof RequisitionStatus ? $status : RequisitionStatus::from((string) $status);
    }

    /**
     * Openings not yet committed to a selected, offered or joined candidate.
     */
    private function openingsLeft(RecruitmentRequisition $requisition): int
    {
        $committedStages = array_map(
            fn (CandidateStage $stage): string => $stage->value,
            array_filter(CandidateStage::cases(), fn (CandidateStage $stage): bool => $stage->order() >= CandidateStage::Selected->order()),
        );

        return $requisition->openings - CandidateApplication::query()
            ->where('requisition_id', $requisition->id)
            ->where('status', ApplicationStatus::Active)
            ->whereIn('current_stage', $committedStages)
            ->count();
    }

    private function joinedCount(RecruitmentRequisition $requisition): int
    {
        return CandidateApplication::query()
            ->where('requisition_id', $requisition->id)
            ->where('status', ApplicationStatus::Active)
            ->whereIn('current_stage', [CandidateStage::Joined->value, CandidateStage::DocumentsCompleted->value, CandidateStage::OnboardingCompleted->value])
            ->count();
    }

    private function weekday(CarbonImmutable $date): CarbonImmutable
    {
        return match (true) {
            $date->isSaturday() => $date->addDays(2),
            $date->isSunday() => $date->addDay(),
            default => $date,
        };
    }

    /**
     * @return list<CandidateDocument>
     */
    private function requestDocuments(CandidateJoining $joining, CandidateApplication $application): array
    {
        $types = [DocumentType::Resume, DocumentType::IdProof, DocumentType::AddressProof, DocumentType::EducationCertificate, DocumentType::PhotoId, DocumentType::BankDetails];

        if ((float) $application->candidate->total_experience >= 0.5) {
            $types[] = DocumentType::ExperienceLetter;
            $types[] = DocumentType::SalarySlip;
        }

        return array_map(fn (DocumentType $type): CandidateDocument => CandidateDocument::query()->create([
            'candidate_joining_id' => $joining->id,
            'document_type' => $type,
            'status' => DocumentStatus::Pending,
        ]), $types);
    }

    /**
     * @param  list<CandidateDocument>  $documents
     */
    private function updateDocuments(array $documents, DocumentStatus $status, ?Employee $verifier = null): void
    {
        foreach ($documents as $document) {
            $document->update([
                'status' => $status,
                'verified_by' => $status === DocumentStatus::Verified ? $verifier?->id : null,
                'verified_at' => $status === DocumentStatus::Verified ? $this->ctx->now() : null,
            ]);
        }
    }

    private function cost(RecruitmentCostType $type, int $amount, ?string $source, ?RecruitmentRequisition $requisition, ?string $campaign, string $remarks): void
    {
        $incurredOn = $this->ctx->now();
        $age = (int) abs($incurredOn->diffInDays($this->ctx->today));

        RecruitmentCost::query()->create([
            'requisition_id' => $requisition?->id,
            'department_id' => $requisition?->department_id,
            'source_id' => $source !== null ? $this->ctx->source($source)->id : null,
            'location_id' => $requisition?->location_id,
            'cost_type' => $type,
            'campaign' => $campaign,
            'amount' => $amount,
            'status' => match (true) {
                $age > 45 => RecruitmentCostStatus::Paid,
                $age > 12 => RecruitmentCostStatus::Approved,
                default => RecruitmentCostStatus::Draft,
            },
            'incurred_on' => $incurredOn->toDateString(),
            'remarks' => $remarks,
            'created_by' => $this->ctx->person('vp_hr')->id,
        ]);
    }

    /**
     * Each recruiter's day-to-day calls, WhatsApp messages and emails beyond the journeys above —
     * the volume the call targets and the activity dashboards measure.
     */
    private function recordBackgroundActivity(): void
    {
        $firstDay = $this->ctx->today->subDays(max(14, $this->ctx->scaled(180)))->startOfDay();
        $elapsedToday = max(0.0, min(1.0, ($this->ctx->today->hour * 60 + $this->ctx->today->minute - 570) / 555));
        $rows = [];

        foreach (self::PACE as $key => $pace) {
            $recruiter = $this->ctx->person($key);
            $candidates = $this->ctx->candidatesByRecruiter[$recruiter->id] ?? [];
            $available = 0;

            for ($day = $firstDay; $day->lessThanOrEqualTo($this->ctx->today); $day = $day->addDay()) {
                if ($day->isSunday()) {
                    continue;
                }

                $dayShare = $day->isSameDay($this->ctx->today) ? $elapsedToday : 1.0;
                $dayEnd = $day->endOfDay()->getTimestamp();

                while ($available < count($candidates) && $candidates[$available][1] <= $dayEnd) {
                    $available++;
                }

                $volumes = [
                    [ActivityType::Call, (int) round($this->ctx->number(33, 46) * $pace * $dayShare)],
                    [ActivityType::WhatsApp, (int) round($this->ctx->number(4, 10) * $dayShare)],
                    [ActivityType::Email, (int) round($this->ctx->number(1, 4) * $dayShare)],
                ];

                foreach ($volumes as [$type, $count]) {
                    for ($i = 0; $i < $count; $i++) {
                        $at = $day->setTime(9, 30)->addMinutes($this->ctx->number(0, (int) (555 * $dayShare)))->toDateTimeString();

                        $rows[] = [
                            'recruiter_id' => $recruiter->id,
                            'candidate_id' => $available > 0 ? $candidates[$this->ctx->number(0, $available - 1)][0] : null,
                            'candidate_application_id' => null,
                            'activity_type' => $type->value,
                            'activity_datetime' => $at,
                            'outcome' => $type === ActivityType::Call ? $this->ctx->weighted(self::CALL_OUTCOMES) : null,
                            'remarks' => null,
                            'created_by' => $recruiter->id,
                            'created_at' => $at,
                            'updated_at' => $at,
                        ];

                        if (count($rows) >= 500) {
                            DB::table('recruitment_daily_activities')->insert($rows);
                            $rows = [];
                        }
                    }
                }
            }
        }

        if ($rows !== []) {
            DB::table('recruitment_daily_activities')->insert($rows);
        }
    }

    private function recordManualActivities(): void
    {
        $entries = [
            ['r_arjun', 'am_west', 40, TargetMetric::ProfilesSourced, 35, 'Walk-in drive at the Mumbai office — CVs collected on paper.'],
            ['r_imran', 'am_north', 26, TargetMetric::ProfilesSourced, 28, 'Job fair at Salt Lake Sector V.'],
            ['r_lakshmi', 'am_south', 18, TargetMetric::ProfilesSourced, 22, 'Delivery associate drive with a local skilling partner.'],
            ['r_karthik', 'am_south', 12, TargetMetric::Calls, 30, 'Calls made from a personal phone during the dialler outage.'],
            ['r_priya', 'am_west', 8, TargetMetric::InterestedCandidates, 6, 'Referral drive — interested candidates captured offline.'],
            ['r_rohit', 'am_north', 5, TargetMetric::ProfilesSourced, 15, 'Campus visit — Gurugram college placement cell.'],
            ['r_divya', 'am_south', 3, TargetMetric::Screening, 5, 'Screened candidates at the tech meetup.'],
        ];

        foreach ($entries as [$recruiter, $recordedBy, $daysAgo, $metric, $count, $remarks]) {
            $day = $this->ctx->officeHours($this->ctx->today->subDays($daysAgo)->setTime(18, 0));

            if ($day->greaterThan($this->ctx->today)) {
                continue;
            }

            DemoContext::freeze($day);
            $this->ctx->actAs($this->ctx->person($recordedBy));

            RecruitmentManualActivity::query()->create([
                'recruiter_id' => $this->ctx->person($recruiter)->id,
                'activity_date' => $day->toDateString(),
                'metric' => $metric->value,
                'count' => $count,
                'remarks' => $remarks,
                'created_by' => $this->ctx->person($recordedBy)->id,
            ]);
        }

        DemoContext::freeze(null);
        $this->ctx->actAs(null);
    }

    /**
     * A few candidates who re-applied under the same mobile or email, for the duplicate review.
     */
    private function recordDuplicateCandidates(): void
    {
        $originals = Candidate::query()->whereNotNull('email')->orderBy('id')->get();

        if ($originals->isEmpty()) {
            return;
        }

        foreach (range(1, 6) as $index) {
            /** @var Candidate $original */
            $original = $originals[$this->ctx->number(0, $originals->count() - 1)];
            $byMobile = $index <= 3;

            DemoContext::freeze($this->ctx->officeHours($this->ctx->today->subDays($this->ctx->number(1, 20))->setTime(12, 0)));

            Candidate::query()->create([
                'candidate_code' => $this->ctx->code('CAND'),
                'full_name' => $original->full_name,
                'mobile' => $byMobile ? $original->mobile : $this->ctx->mobile(),
                'email' => $byMobile ? null : $original->email,
                'location' => $original->location,
                'current_city' => $original->current_city,
                'qualification' => $original->qualification,
                'total_experience' => $original->total_experience,
                'skills' => $original->skills,
                'source_id' => $this->ctx->source($this->ctx->pick(['Naukri', 'Walk-in', 'Indeed']))->id,
                'remarks' => 'Re-applied through a job portal.',
                'created_by' => $original->created_by,
            ]);
        }

        DemoContext::freeze(null);

        $reviewer = $this->ctx->person('am_west');

        CandidateDuplicateMatch::query()->orderBy('id')->take(2)->get()->each(function (CandidateDuplicateMatch $match, int $index) use ($reviewer): void {
            $match->update([
                'status' => $index === 0 ? DuplicateMatchStatus::ConfirmedDuplicate : DuplicateMatchStatus::NotDuplicate,
                'resolved_by' => $reviewer->id,
                'resolved_at' => $this->ctx->today->subDay(),
            ]);
        });
    }
}
