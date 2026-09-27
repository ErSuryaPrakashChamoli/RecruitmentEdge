<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\EmployeeStatus;
use App\Enums\ReferralIncentiveStatus;
use App\Enums\ReferralRelationship;
use App\Enums\ReferralStatus;
use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Events\CandidateStageChanged;
use App\Events\ReferralStatusChanged;
use App\Events\ReferralSubmitted;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\EmployeeReferral;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentRequisition;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Employee referral workflow (Phase 4):
 *
 * Employee → submit (duplicate check) → referral → review (accept/reject) → application in the
 * recruitment pipeline → status follows the application → Joined → referral bonus through the
 * existing incentive engine (ReferralJoining trigger, beneficiary = referrer).
 *
 * The only writer of employee_referrals status/linkage. Never duplicates a Candidate Master
 * record: an existing candidate is referenced, and creating a new one over a strong duplicate
 * needs an audited justification (CandidateDuplicateDetector::recordOverride()).
 */
class ReferralService
{
    public function __construct(
        private readonly CandidateDuplicateDetector $duplicates,
        private readonly SequenceCodeGenerator $codes,
        private readonly CandidateTimelineService $timeline,
        private readonly RecruiterIncentiveCalculator $incentives,
    ) {}

    /**
     * @param  array<string, mixed>  $candidateData  full_name, mobile, email, … for a new candidate (ignored when $existing is given)
     * @param  array{relationship: ReferralRelationship|string, notes?: string|null, incentive_eligible?: bool}  $referralData
     *
     * @throws DuplicateCandidateFoundException when the new candidate strongly matches existing ones and no justification is given
     */
    public function submit(
        Employee $referrer,
        array $candidateData,
        array $referralData,
        ?RecruitmentRequisition $requisition = null,
        ?Candidate $existing = null,
        ?string $duplicateJustification = null,
    ): EmployeeReferral {
        if ($requisition !== null && ! $requisition->acceptsApplications()) {
            throw new DomainException("{$requisition->code} is not open for referrals.");
        }

        $matches = $existing === null ? $this->duplicates->strongMatches($candidateData) : collect();

        if ($matches->isNotEmpty() && blank($duplicateJustification)) {
            throw new DuplicateCandidateFoundException($matches);
        }

        if ($existing !== null && $this->hasOpenReferral($existing, $requisition)) {
            throw new DomainException("{$existing->full_name} has already been referred".($requisition !== null ? " for {$requisition->code}" : '').'.');
        }

        $source = CandidateSource::activeByCode(CandidateSource::CODE_EMPLOYEE_REFERRAL);

        $referral = DB::transaction(function () use ($referrer, $candidateData, $referralData, $requisition, $existing, $matches, $duplicateJustification, $source): EmployeeReferral {
            $candidate = $existing ?? $this->createCandidate($referrer, $candidateData, $source);

            if ($existing === null && $matches->isNotEmpty()) {
                $this->duplicates->recordOverride($candidate, $matches, (string) $duplicateJustification, $referrer);
            }

            $eligible = (bool) ($referralData['incentive_eligible'] ?? true);

            $referral = new EmployeeReferral([
                'referral_code' => $this->codes->next('REF'),
                'referrer_id' => $referrer->id,
                'candidate_id' => $candidate->id,
                'requisition_id' => $requisition?->id,
                'relationship' => $referralData['relationship'],
                'referred_at' => now()->toDateString(),
                'source_id' => $source?->id,
                'notes' => $referralData['notes'] ?? null,
                'incentive_eligible' => $eligible,
                'created_by' => $referrer->id,
            ]);
            $referral->forceFill([
                'status' => ReferralStatus::Submitted,
                'incentive_status' => $eligible ? ReferralIncentiveStatus::Eligible : ReferralIncentiveStatus::NotEligible,
            ])->save();

            $this->timeline->record(
                $candidate,
                TimelineEventType::Referral,
                "Referred by {$referrer->fullName()}".($requisition !== null ? " for {$requisition->code}" : ''),
                $referral->relationship->label(),
                TimelineSource::Employee,
                actor: $referrer,
                related: ['subject' => $referral],
                metadata: ['referral_id' => $referral->id, 'existing_candidate' => $existing !== null],
            );

            return $referral;
        });

        ReferralSubmitted::dispatch($referral);

        return $referral;
    }

    /**
     * A reviewer's manual status change (Under Review, Accepted, Rejected, Closed).
     */
    public function moveTo(EmployeeReferral $referral, ReferralStatus $status, ?Employee $actor = null, ?string $remarks = null, ?RecruitmentRejectionReason $reason = null): EmployeeReferral
    {
        if (! in_array($status, $referral->status->manualTransitions(), true)) {
            throw new DomainException("A referral cannot move from {$referral->status->label()} to {$status->label()}.");
        }

        if ($status === ReferralStatus::Rejected && $reason === null) {
            throw new DomainException('A rejection reason is required to reject a referral.');
        }

        $attributes = match ($status) {
            ReferralStatus::UnderReview, ReferralStatus::Accepted => ['reviewed_by' => $actor?->id, 'reviewed_at' => now()],
            ReferralStatus::Rejected => ['reviewed_by' => $actor?->id, 'reviewed_at' => now(), 'rejection_reason_id' => $reason?->id, 'rejection_remarks' => $remarks],
            ReferralStatus::Closed => ['closed_at' => now()],
            default => [],
        };

        return $this->writeStatus($referral, $status, $attributes, $actor, $remarks);
    }

    /**
     * Accepts the referral into the pipeline: links (or creates) the candidate's application for
     * the referred requisition and moves the referral to In Process. A general referral (no
     * requisition) needs $requisition here.
     */
    public function accept(EmployeeReferral $referral, Employee $recruiter, ?Employee $actor = null, ?RecruitmentRequisition $requisition = null): EmployeeReferral
    {
        if (! in_array(ReferralStatus::Accepted, $referral->status->manualTransitions(), true)) {
            throw new DomainException("A referral that is {$referral->status->label()} cannot be accepted.");
        }

        $requisition ??= $referral->requisition;

        if ($requisition === null) {
            throw new DomainException('Choose the requisition to put this referred candidate forward for.');
        }

        if (! $requisition->acceptsApplications()) {
            throw new DomainException("{$requisition->code} is not open for applications.");
        }

        return DB::transaction(function () use ($referral, $recruiter, $actor, $requisition): EmployeeReferral {
            $application = CandidateApplication::query()
                ->where('candidate_id', $referral->candidate_id)
                ->where('requisition_id', $requisition->id)
                ->first()
                ?? CandidateApplication::query()->create([
                    'application_code' => $this->codes->next('APP'),
                    'candidate_id' => $referral->candidate_id,
                    'requisition_id' => $requisition->id,
                    'recruiter_id' => $recruiter->id,
                    'current_stage' => CandidateStage::Sourced,
                    'application_date' => now()->toDateString(),
                    'status' => ApplicationStatus::Active,
                    'remarks' => "Referral {$referral->referral_code}",
                ]);

            $referral->forceFill(['requisition_id' => $requisition->id, 'candidate_application_id' => $application->id])->save();

            $this->writeStatus($referral, ReferralStatus::Accepted, ['reviewed_by' => $actor?->id, 'reviewed_at' => now()], $actor, "Accepted into {$application->application_code}");

            return $this->syncFromApplication($referral->fresh(), $application);
        });
    }

    /**
     * Brings the referral's pipeline status in line with its application: never backwards, never
     * out of Closed. Called for every CandidateStageChanged (see SyncReferralsWithApplication).
     * Reaching Joined prices the referral bonus through the existing incentive engine.
     */
    public function syncFromApplication(EmployeeReferral $referral, ?CandidateApplication $application = null): EmployeeReferral
    {
        $application ??= $referral->candidateApplication;

        if ($application === null || $referral->status === ReferralStatus::Closed) {
            return $referral;
        }

        $target = match (true) {
            $application->status === ApplicationStatus::Rejected => ReferralStatus::Rejected,
            $application->status === ApplicationStatus::Dropout => ReferralStatus::DidNotJoin,
            $application->current_stage->order() >= CandidateStage::Joined->order() => ReferralStatus::Joined,
            $application->current_stage->order() >= CandidateStage::OfferReleased->order() => ReferralStatus::OfferReleased,
            $application->current_stage->order() >= CandidateStage::Selected->order() => ReferralStatus::Selected,
            default => ReferralStatus::InProcess,
        };

        $reopened = ! $referral->status->isOpen() && $target->isOpen() && $application->status === ApplicationStatus::Active;

        if ($target === $referral->status || (! $reopened && $target->progress() < $referral->status->progress())) {
            return $referral;
        }

        $attributes = match ($target) {
            ReferralStatus::Joined => ['joining_date' => $application->joining?->actual_doj ?? now()->toDateString()],
            ReferralStatus::Rejected => ['rejection_reason_id' => $application->rejection_reason_id],
            ReferralStatus::DidNotJoin => ['incentive_status' => $referral->incentive_eligible ? ReferralIncentiveStatus::Forfeited : $referral->incentive_status],
            default => [],
        };

        $referral = $this->writeStatus($referral, $target, $attributes, null, 'Updated from application '.$application->application_code);

        if ($target === ReferralStatus::Joined) {
            $this->priceReferralBonus($referral);
        }

        return $referral;
    }

    public function syncForStageChange(CandidateStageChanged $event): void
    {
        EmployeeReferral::query()
            ->where('candidate_application_id', $event->application->id)
            ->get()
            ->each(fn (EmployeeReferral $referral) => $this->syncFromApplication($referral, $event->application));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeStatus(EmployeeReferral $referral, ReferralStatus $status, array $attributes, ?Employee $actor, ?string $remarks): EmployeeReferral
    {
        $from = $referral->status;

        DB::transaction(function () use ($referral, $status, $attributes, $actor, $remarks, $from): void {
            $referral->forceFill([...$attributes, 'status' => $status])->save();

            $this->timeline->record(
                $referral->candidate_id,
                TimelineEventType::Referral,
                "Referral {$referral->referral_code}: {$from->label()} → {$status->label()}",
                $remarks,
                $actor !== null ? TimelineSource::Recruiter : TimelineSource::System,
                TimelineVisibility::Internal,
                $actor,
                ['application' => $referral->candidateApplication, 'subject' => $referral],
            );
        });

        ReferralStatusChanged::dispatch($referral, $from, $status, $actor);

        return $referral;
    }

    /**
     * Why this referral may not earn a referral bonus right now, or null when it may. The
     * explicit eligibility rule (Phase 4.1): the referral is marked eligible, the referred
     * candidate has actually joined through the linked application, the referrer is not that
     * application's own recruiter (who is already paid through recruiter incentives), and the
     * referrer is still an active employee.
     */
    public function referralIncentiveIneligibility(EmployeeReferral $referral): ?string
    {
        $application = $referral->candidateApplication;

        return match (true) {
            ! $referral->incentive_eligible => 'The referral is marked as not eligible for a bonus.',
            $application === null => 'The referred candidate is not in the pipeline.',
            $referral->status !== ReferralStatus::Joined,
            $application->status !== ApplicationStatus::Active,
            $application->current_stage->order() < CandidateStage::Joined->order() => 'The referred candidate has not joined.',
            $referral->referrer_id === $application->recruiter_id => 'The referrer is the recruiter on this application and is paid through recruiter incentives.',
            $referral->referrer?->status === EmployeeStatus::Inactive => 'The referring employee is no longer active.',
            default => null,
        };
    }

    /**
     * A reviewer's manual eligibility decision — always with a reason, always audited, and never
     * after the bonus has been calculated (from then on corrections go through the incentive
     * engine's own adjustment/reversal flow). Making a joined referral eligible prices it now.
     */
    public function setIncentiveEligibility(EmployeeReferral $referral, bool $eligible, string $reason, ?Employee $actor = null): EmployeeReferral
    {
        if ($referral->incentive_status === ReferralIncentiveStatus::Calculated) {
            throw new DomainException('The bonus is already calculated — adjust or reverse it from Incentive Calculations instead.');
        }

        if (blank($reason)) {
            throw new DomainException('A reason is required to change referral bonus eligibility.');
        }

        $before = ['incentive_eligible' => $referral->incentive_eligible, 'incentive_status' => $referral->incentive_status?->value];

        DB::transaction(function () use ($referral, $eligible, $reason, $actor, $before): void {
            $referral->forceFill([
                'incentive_eligible' => $eligible,
                'incentive_status' => $eligible ? ReferralIncentiveStatus::Eligible : ReferralIncentiveStatus::NotEligible,
            ])->save();

            AuditLog::record($referral, 'referral_incentive_override', $before, [
                'incentive_eligible' => $eligible,
                'reason' => $reason,
                'actor_employee_id' => $actor?->id,
            ]);

            $this->timeline->record(
                $referral->candidate_id,
                TimelineEventType::Referral,
                "Referral {$referral->referral_code}: bonus marked ".($eligible ? 'eligible' : 'not eligible'),
                $reason,
                TimelineSource::Recruiter,
                actor: $actor,
                related: ['subject' => $referral],
            );
        });

        if ($eligible && $referral->status === ReferralStatus::Joined) {
            $this->priceReferralBonus($referral);
        }

        return $referral;
    }

    private function priceReferralBonus(EmployeeReferral $referral): void
    {
        if (! $referral->incentive_eligible) {
            return;
        }

        $ineligibility = $this->referralIncentiveIneligibility($referral);

        if ($ineligibility !== null) {
            $referral->forceFill(['incentive_status' => ReferralIncentiveStatus::NotEligible])->save();
            AuditLog::record($referral, 'referral_incentive_ineligible', null, ['reason' => $ineligibility]);

            return;
        }

        $calculations = $this->incentives->calculateForReferralJoining($referral);

        $referral->forceFill($calculations->isEmpty()
            ? ['incentive_status' => ReferralIncentiveStatus::NoMatchingRule]
            : ['incentive_status' => ReferralIncentiveStatus::Calculated, 'incentive_calculation_id' => $calculations->first()->id])
            ->save();

        AuditLog::record($referral, 'referral_incentive_evaluated', null, [
            'result' => $referral->incentive_status->value,
            'calculation_ids' => $calculations->pluck('id')->all(),
        ]);
    }

    private function hasOpenReferral(Candidate $candidate, ?RecruitmentRequisition $requisition): bool
    {
        return EmployeeReferral::query()
            ->where('candidate_id', $candidate->id)
            ->when($requisition !== null, fn ($q) => $q->where('requisition_id', $requisition->id), fn ($q) => $q->whereNull('requisition_id'))
            ->whereNotIn('status', [ReferralStatus::Rejected, ReferralStatus::DidNotJoin, ReferralStatus::Closed])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createCandidate(Employee $referrer, array $data, ?CandidateSource $source): Candidate
    {
        $source ??= CandidateSource::query()->where('is_active', true)->orderBy('id')->firstOrFail();

        return Candidate::query()->create([
            ...array_intersect_key($data, array_flip([
                'full_name', 'mobile', 'alternate_mobile', 'email', 'current_city', 'location', 'qualification',
                'total_experience', 'current_company', 'current_designation', 'notice_period_days', 'skills', 'resume_path',
            ])),
            'candidate_code' => $this->codes->next('CAND'),
            'source_id' => $source->id,
            'source_details' => 'Employee referral',
            'referral_employee_id' => $referrer->id,
            'created_by' => $referrer->id,
        ]);
    }
}
