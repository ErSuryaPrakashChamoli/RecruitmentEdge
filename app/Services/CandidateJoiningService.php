<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\DocumentStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Events\CandidateJoined;
use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\Lifecycle\LifecycleGuard;
use App\Services\Lifecycle\RowLock;
use Closure;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a CandidateJoining's status in sync with its application's pipeline stage — a joining
 * confirmation/actual join is also a pipeline event, not just a Joining Tracker field.
 *
 * Phase 8.9 (P89-DQ-003): every transition runs in one transaction that locks the application, then
 * the joining (RowLock order), and checks the joining's latest committed status — so Joined racing
 * Dropout/No-show, or a double Joined with two different dates, is refused instead of paying an
 * incentive for a non-hire or pricing two incentive periods for one join.
 *
 * Phase 8.10 (P810-DI-04): a joining completes the offer chain — application, Selected, offer,
 * Accepted, joining. Staff create one only for an accepted offer (createForApplication(), which
 * derives the offer), and Mark Joined needs the joining's offer to be that application's accepted
 * offer, so a hand-made joining can no longer move an application to Joined, price an incentive
 * or count a hire.
 */
class CandidateJoiningService
{
    public function __construct(
        private readonly StageTransitionService $stageTransitions,
        private readonly RecruiterIncentiveCalculator $incentiveCalculator,
        private readonly NotificationDispatchService $notifications,
        private readonly HierarchyService $hierarchy,
    ) {}

    /**
     * Phase 8.10 (P810-DI-04): the staff "New joining" path, and the recovery for an accepted offer
     * whose joining record is missing. The actor needs joining.confirm and the application in their
     * hierarchy; the application must be active, have an accepted offer and no joining yet. The
     * offer is the application's accepted one — never chosen — and the creation is audited.
     *
     * @param  array{expected_doj?: mixed, documents_status?: mixed, remarks?: string|null}  $details
     *
     * @throws DomainException when the actor may not create it or the offer chain is incomplete
     */
    public function createForApplication(CandidateApplication $application, User $actor, array $details = []): CandidateJoining
    {
        if (! $actor->can('joining.confirm') || ! $this->hierarchy->canView($actor, $application->recruiter)) {
            throw new DomainException('You cannot create a joining record for this application.');
        }

        return DB::transaction(function () use ($application, $actor, $details): CandidateJoining {
            RowLock::fresh($application);

            if ($application->status !== ApplicationStatus::Active) {
                throw new DomainException("This application is {$application->status->label()}; a joining record needs an active application.");
            }

            if (CandidateJoining::query()->where('candidate_application_id', $application->id)->exists()) {
                throw new DomainException('This application already has a joining record.');
            }

            $offer = Offer::query()->where('candidate_application_id', $application->id)->where('status', OfferStatus::Accepted)->latest('id')->first()
                ?? throw new DomainException('A joining record follows an accepted offer — record the offer as accepted first.');

            $joining = $this->createForAcceptedOffer($offer);
            $joining->forceFill([
                ...array_filter(collect($details)->only(['expected_doj', 'documents_status', 'remarks'])->all(), filled(...)),
                'created_by' => $actor->employee_id,
            ])->save();

            AuditLog::record($joining, 'joining_created_for_accepted_offer', null, ['offer_id' => $offer->id, 'by_user_id' => $actor->id]);

            return $joining->refresh();
        });
    }

    /**
     * Phase 8.3: creates the joining record for an accepted offer, inside the offer's own
     * transaction (OfferService::moveTo), so an accepted offer and its joining record are one fact.
     * One joining per application: a still-pending record is re-linked to the newly accepted offer;
     * a closed one is never silently reopened.
     */
    public function createForAcceptedOffer(Offer $offer): CandidateJoining
    {
        $existing = CandidateJoining::query()->where('candidate_application_id', $offer->candidate_application_id)->first();
        $expectedDoj = $offer->expected_joining_date ?? $offer->offer_date->copy()->addWeeks(2);

        if ($existing === null) {
            return CandidateJoining::query()->create([
                'candidate_application_id' => $offer->candidate_application_id,
                'offer_id' => $offer->id,
                'expected_doj' => $expectedDoj,
                'created_by' => $offer->created_by,
            ]);
        }

        if (! in_array($existing->status, [JoiningStatus::Expected, JoiningStatus::Confirmed], true)) {
            throw new DomainException("This application's joining record is already {$existing->status->label()}; it cannot take a newly accepted offer.");
        }

        if ((int) $existing->offer_id !== $offer->id) {
            LifecycleGuard::allow(fn () => $existing->forceFill(['offer_id' => $offer->id, 'expected_doj' => $expectedDoj])->save());
        }

        return $existing;
    }

    public function confirm(CandidateJoining $joining, ?Employee $actor = null): CandidateJoining
    {
        return $this->lockedActive($joining, function () use ($joining, $actor): CandidateJoining {
            LifecycleGuard::allow(fn () => $joining->forceFill(['status' => JoiningStatus::Confirmed, 'confirmed_at' => now()])->save());

            $this->stageTransitions->transitionTo($joining->candidateApplication, CandidateStage::JoiningConfirmed, $actor);

            return $joining;
        });
    }

    public function markJoined(CandidateJoining $joining, ?Carbon $actualDoj = null, ?Employee $actor = null): CandidateJoining
    {
        return $this->lockedActive($joining, function () use ($joining, $actualDoj, $actor): CandidateJoining {
            $this->guardAcceptedOffer($joining);

            LifecycleGuard::allow(fn () => $joining->forceFill(['status' => JoiningStatus::Joined, 'actual_doj' => $actualDoj ?? now()])->save());

            $this->stageTransitions->transitionTo($joining->candidateApplication, CandidateStage::Joined, $actor);

            // Section 25: joining incentives are calculated the moment a join is confirmed as a
            // fact, never merely on selection or offer acceptance.
            $this->incentiveCalculator->calculateForJoining($joining);

            // Phase 8.2: the completed-hire anchor for the Outcome Loop (ids only, after commit).
            $application = $joining->candidateApplication;
            CandidateJoined::dispatch($application->candidate_id, $application->id, $application->requisition_id, $joining->id, $joining->actual_doj->toDateString());

            return $joining;
        });
    }

    public function markNoShow(CandidateJoining $joining, RecruitmentRejectionReason $reason, ?Employee $actor = null): CandidateJoining
    {
        return $this->lockedActive($joining, function () use ($joining, $reason, $actor): CandidateJoining {
            LifecycleGuard::allow(fn () => $joining->forceFill(['status' => JoiningStatus::NoShow, 'dropout_reason_id' => $reason->id])->save());

            $this->stageTransitions->dropout($joining->candidateApplication, $reason, $actor, 'Did not join (no-show)');

            return $joining;
        });
    }

    public function markDropout(CandidateJoining $joining, RecruitmentRejectionReason $reason, ?Employee $actor = null): CandidateJoining
    {
        return $this->lockedActive($joining, function () use ($joining, $reason, $actor): CandidateJoining {
            LifecycleGuard::allow(fn () => $joining->forceFill(['status' => JoiningStatus::Dropout, 'dropout_reason_id' => $reason->id])->save());

            $this->stageTransitions->dropout($joining->candidateApplication, $reason, $actor, 'Dropped out before joining');

            $application = $joining->candidateApplication;
            DB::afterCommit(fn () => $this->notifications->alert(
                $application->recruiter?->user,
                'Joining',
                'Candidate dropout',
                "{$application->candidate->full_name} dropped out before joining: {$reason->name}.",
                'danger',
                CandidateJoiningResource::getUrl('edit', ['record' => $joining]),
            ));

            return $joining;
        });
    }

    /**
     * Post-join milestone: the joiner's documents are verified. Only valid once the candidate has
     * actually joined; sets `documents_status` and advances the application stage together.
     */
    public function markDocumentsCompleted(CandidateJoining $joining, ?Employee $actor = null): CandidateJoining
    {
        return $this->locked($joining, function () use ($joining, $actor): CandidateJoining {
            $this->guardPostJoinMilestone($joining, CandidateStage::DocumentsCompleted);

            LifecycleGuard::allow(fn () => $joining->forceFill(['documents_status' => DocumentStatus::Verified])->save());

            $this->stageTransitions->transitionTo($joining->candidateApplication, CandidateStage::DocumentsCompleted, $actor);

            return $joining;
        });
    }

    /**
     * Final post-join milestone. Requires documents to be completed first so the stage path stays
     * Joined -> Documents Completed -> Onboarding Completed.
     */
    public function markOnboardingCompleted(CandidateJoining $joining, ?Employee $actor = null): CandidateJoining
    {
        return $this->locked($joining, function () use ($joining, $actor): CandidateJoining {
            $this->guardPostJoinMilestone($joining, CandidateStage::OnboardingCompleted);

            if ($joining->candidateApplication->current_stage->order() < CandidateStage::DocumentsCompleted->order()) {
                throw new DomainException('Documents must be completed before onboarding can be marked complete.');
            }

            $this->stageTransitions->transitionTo($joining->candidateApplication, CandidateStage::OnboardingCompleted, $actor);

            return $joining;
        });
    }

    private function guardPostJoinMilestone(CandidateJoining $joining, CandidateStage $milestone): void
    {
        if ($joining->status !== JoiningStatus::Joined) {
            throw new DomainException("{$milestone->label()} can only be recorded once the candidate has joined.");
        }

        if ($joining->candidateApplication->current_stage->order() >= $milestone->order()) {
            throw new DomainException("{$milestone->label()} has already been recorded for this candidate.");
        }
    }

    /**
     * Phase 8.3: the joining will not happen because the application closed (e.g. the company
     * rejected it). Not a no-show or dropout; records no joining outcome. The reason is kept in the
     * joining's remarks and the change is audited (the model is Auditable).
     */
    public function cancel(CandidateJoining $joining, string $reason, ?Employee $actor = null): CandidateJoining
    {
        if (blank($reason)) {
            throw new DomainException('A reason is required to cancel a joining.');
        }

        return $this->lockedActive($joining, function () use ($joining, $reason, $actor): CandidateJoining {
            LifecycleGuard::allow(fn () => $joining->forceFill([
                'status' => JoiningStatus::Cancelled,
                'remarks' => trim(($joining->remarks ? $joining->remarks."\n" : '').'Cancelled: '.$reason.($actor !== null ? " ({$actor->fullName()})" : '')),
            ])->save());

            return $joining;
        });
    }

    /**
     * Phase 8.3 cascade: the candidate dropped out of the application while the joining was still
     * pending — the joining records the same dropout (same reason) without re-closing the
     * application. Only StageTransitionService's closure cascade calls this.
     */
    public function recordApplicationDropout(CandidateJoining $joining, RecruitmentRejectionReason $reason): CandidateJoining
    {
        return $this->lockedActive($joining, function () use ($joining, $reason): CandidateJoining {
            LifecycleGuard::allow(fn () => $joining->forceFill(['status' => JoiningStatus::Dropout, 'dropout_reason_id' => $reason->id])->save());

            return $joining;
        });
    }

    /**
     * Runs $change in one transaction holding the application's and then the joining's row lock,
     * with the joining refreshed from its locked row.
     *
     * @param  Closure(): CandidateJoining  $change
     */
    private function locked(CandidateJoining $joining, Closure $change): CandidateJoining
    {
        return DB::transaction(function () use ($joining, $change): CandidateJoining {
            RowLock::key(CandidateApplication::class, $joining->candidate_application_id);
            RowLock::fresh($joining);

            return $change();
        });
    }

    /**
     * As locked(), refusing a joining that is no longer pending on its latest committed status.
     *
     * @param  Closure(): CandidateJoining  $change
     */
    private function lockedActive(CandidateJoining $joining, Closure $change): CandidateJoining
    {
        return $this->locked($joining, function () use ($joining, $change): CandidateJoining {
            $this->guardActive($joining);

            return $change();
        });
    }

    /**
     * Phase 8.10 (P810-DI-04): the hire completes the offer chain. Read under the application lock,
     * which OfferService takes first too.
     */
    private function guardAcceptedOffer(CandidateJoining $joining): void
    {
        $accepted = Offer::query()
            ->whereKey($joining->offer_id)
            ->where('candidate_application_id', $joining->candidate_application_id)
            ->where('status', OfferStatus::Accepted)
            ->exists();

        if (! $accepted) {
            throw new DomainException('A joining is marked Joined only on an accepted offer for this application — record the offer as accepted first.');
        }
    }

    private function guardActive(CandidateJoining $joining): void
    {
        if (in_array($joining->status, [JoiningStatus::Joined, JoiningStatus::NoShow, JoiningStatus::Dropout, JoiningStatus::Cancelled], true)) {
            throw new DomainException("This joining record is already {$joining->status->label()} and cannot be changed further.");
        }
    }
}
