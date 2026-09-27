<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\OfferRevisionStatus;
use App\Enums\OfferStatus;
use App\Events\OfferAccepted;
use App\Events\OfferReleased;
use App\Events\OfferRevisionReleased;
use App\Events\OfferStatusChanged;
use App\Filament\Resources\Offers\OfferResource;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\Lifecycle\LifecycleGuard;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only code path allowed to change an offer's status. Every change is written atomically with
 * a permanent `offer_status_histories` row — offers are financially sensitive (Section 27/28) and
 * must never be silently overwritten. Phase 8.3: offers are raised only on eligible applications
 * (offerBlocker), released terms change only through audited revisions, and the model refuses any
 * other write to status, application or released terms.
 */
class OfferService
{
    /**
     * @var array<string, array<int, string>>
     */
    private const array ALLOWED_TRANSITIONS = [
        'draft' => ['initiated', 'withdrawn'],
        'initiated' => ['released', 'withdrawn'],
        'released' => ['accepted', 'rejected', 'expired', 'withdrawn'],
        'accepted' => [],
        'rejected' => [],
        'expired' => [],
        'withdrawn' => [],
    ];

    /**
     * Offer statuses that still hold the application: only one of these may exist at a time.
     *
     * @var array<int, OfferStatus>
     */
    public const array OPEN_STATUSES = [OfferStatus::Draft, OfferStatus::Initiated, OfferStatus::Released, OfferStatus::Accepted];

    public function __construct(
        private readonly StageTransitionService $stageTransitions,
        private readonly NotificationDispatchService $notifications,
        private readonly CandidateJoiningService $joinings,
        private readonly OfferLetterIssuanceService $letters,
    ) {}

    /**
     * Phase 8.3 — the one offer-eligibility rule (Filament, service, Copilot, automation): the
     * application is active, has been Selected (or is further along the offer stages), and has no
     * other offer still open. Returns the reason an offer cannot be raised, or null.
     */
    public function offerBlocker(CandidateApplication $application): ?string
    {
        return match (true) {
            $application->status !== ApplicationStatus::Active => "An offer can only be raised on an active application (this one is {$application->status->label()}).",
            $application->current_stage->order() < CandidateStage::Selected->order() => "An offer can only be raised once the candidate is Selected (currently {$application->current_stage->label()}).",
            $application->current_stage->order() >= CandidateStage::OfferAccepted->order() => 'This application already has an accepted offer.',
            $application->offers()->whereIn('status', array_map(fn (OfferStatus $status) => $status->value, self::OPEN_STATUSES))->exists() => 'This application already has an open offer — withdraw it or revise it instead.',
            default => null,
        };
    }

    public function canRaiseOffer(CandidateApplication $application): bool
    {
        return $this->offerBlocker($application) === null;
    }

    /**
     * Applications an offer may be raised on, as a query (for pickers).
     *
     * @param  Builder<CandidateApplication>  $query
     * @return Builder<CandidateApplication>
     */
    public static function eligibleApplications(Builder $query): Builder
    {
        $stages = collect(CandidateStage::cases())
            ->filter(fn (CandidateStage $stage) => $stage->order() >= CandidateStage::Selected->order() && $stage->order() < CandidateStage::OfferAccepted->order())
            ->map(fn (CandidateStage $stage) => $stage->value)
            ->values()
            ->all();

        return $query->where('candidate_applications.status', ApplicationStatus::Active->value)
            ->whereIn('candidate_applications.current_stage', $stages)
            ->whereDoesntHave('offers', fn (Builder $offers) => $offers->whereIn('status', array_map(fn (OfferStatus $status) => $status->value, self::OPEN_STATUSES)));
    }

    /**
     * Creates an offer together with its initial (null -> status) history row, so the trail starts
     * at creation rather than at the first transition.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?Employee $actor = null): Offer
    {
        $application = CandidateApplication::query()->find($attributes['candidate_application_id'] ?? null);

        if ($application === null) {
            throw new DomainException('Choose the application this offer is for.');
        }

        if (($blocker = $this->offerBlocker($application)) !== null) {
            throw new DomainException($blocker);
        }

        return DB::transaction(function () use ($attributes, $actor): Offer {
            $offer = Offer::query()->create([
                ...$attributes,
                'status' => $attributes['status'] ?? OfferStatus::Draft,
                'created_by' => $attributes['created_by'] ?? $actor?->id,
            ]);

            $offer->statusHistory()->create([
                'from_status' => null,
                'to_status' => $offer->status,
                'changed_by' => $actor?->id,
                'remarks' => 'Offer created',
            ]);

            return $offer;
        });
    }

    public function moveTo(
        Offer $offer,
        OfferStatus $to,
        ?Employee $actor = null,
        ?string $remarks = null,
        ?RecruitmentRejectionReason $rejectionReason = null,
    ): Offer {
        if ($to === OfferStatus::Released && ! $this->canRelease($actor)) {
            throw new DomainException('Releasing an offer requires the offers.release permission.');
        }

        if ($to === OfferStatus::Rejected && $rejectionReason === null) {
            throw new DomainException('A rejection reason is required to reject an offer.');
        }

        return DB::transaction(fn (): Offer => LifecycleGuard::allow(function () use ($offer, $to, $actor, $remarks, $rejectionReason): Offer {
            // Phase 8.7 (D8.7-005): decide on the offer's current status under its row lock, so two
            // concurrent moves (a double click, the candidate portal and a recruiter) cannot both
            // pass the transition check.
            Offer::query()->whereKey($offer->getKey())->lockForUpdate()->first();
            $offer->refresh();
            $from = $offer->status;

            if (! in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value], true)) {
                throw new DomainException("Cannot move an offer from {$from->label()} to {$to->label()}.");
            }

            $offer->forceFill([
                'status' => $to,
                'accepted_at' => $to === OfferStatus::Accepted ? now() : $offer->accepted_at,
            ])->save();

            $offer->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => $actor?->id,
                'remarks' => $remarks,
            ]);

            $this->syncApplicationStage($offer, $to, $actor, $rejectionReason);

            if ($to === OfferStatus::Accepted) {
                // Phase 8.3: the joining record is part of the acceptance, in the same transaction;
                // any other offer still open on the application is withdrawn.
                $this->joinings->createForAcceptedOffer($offer);
                $this->withdrawOtherOpenOffers($offer, $actor);
                OfferAccepted::dispatch($offer);
            }

            if ($to === OfferStatus::Released) {
                // Phase 8.6 (D8.6-010): the letter is issued with the release and served from storage.
                $this->letters->issue($offer, null, $actor?->id);
                OfferReleased::dispatch($offer);
            }

            OfferStatusChanged::dispatch($offer, $from, $to, $actor, $remarks);

            $this->notifyStatusChange($offer, $to);

            return $offer;
        }));
    }

    /**
     * Phase 8.3: withdraws every open offer of an application (the application closed — rejection
     * or dropout). Idempotent: already-closed offers are left alone. Returns how many were withdrawn.
     */
    public function withdrawOpenOffers(CandidateApplication $application, ?Employee $actor, string $remarks): int
    {
        return $application->offers()
            ->whereIn('status', [OfferStatus::Draft->value, OfferStatus::Initiated->value, OfferStatus::Released->value])
            ->get()
            ->each(fn (Offer $offer) => $this->moveTo($offer, OfferStatus::Withdrawn, $actor, $remarks))
            ->count();
    }

    /**
     * Phase 8.3: a released offer's terms change only through a revision. Records the terms in
     * force as revision 1 the first time, then a pending revision with the proposed terms and the
     * reason. Nothing on the offer changes until the revision is released.
     *
     * @param  array<string, mixed>  $terms  any of OfferRevision::TERMS
     */
    public function requestRevision(Offer $offer, array $terms, string $reason, User $actor): OfferRevision
    {
        $reason = trim($reason);

        if (! $actor->can('update', $offer)) {
            throw new DomainException('You are not allowed to revise this offer.');
        }

        if ($offer->status !== OfferStatus::Released) {
            throw new DomainException("Only a released offer can be revised (this one is {$offer->status->label()}). Edit a draft directly; an accepted offer is final.");
        }

        if ($reason === '') {
            throw new DomainException('A reason is required to revise an offer.');
        }

        return DB::transaction(function () use ($offer, $terms, $reason, $actor): OfferRevision {
            Offer::query()->whereKey($offer->id)->lockForUpdate()->first();

            if ($offer->revisions()->where('status', OfferRevisionStatus::Pending->value)->exists()) {
                throw new DomainException('A revision of this offer is already awaiting release.');
            }

            if ($offer->revisions()->doesntExist()) {
                $offer->revisions()->create([
                    ...collect(OfferRevision::TERMS)->mapWithKeys(fn (string $term) => [$term => $offer->getRawOriginal($term)])->all(),
                    'revision' => 1,
                    'status' => OfferRevisionStatus::Released,
                    'reason' => 'Terms as originally released',
                    'decided_at' => $offer->statusHistory()->where('to_status', OfferStatus::Released->value)->latest('id')->value('created_at'),
                ]);
            }

            $proposed = collect(OfferRevision::TERMS)->mapWithKeys(fn (string $term) => [$term => array_key_exists($term, $terms) ? $terms[$term] : $offer->getRawOriginal($term)])->all();

            $revision = $offer->revisions()->create([
                ...$proposed,
                'revision' => (int) $offer->revisions()->max('revision') + 1,
                'status' => OfferRevisionStatus::Pending,
                'reason' => mb_substr($reason, 0, 255),
                'requested_by' => $actor->id,
            ]);

            AuditLog::record($offer, 'offer_revision_requested', null, [
                'revision' => $revision->revision, 'changed_terms' => $this->changedTerms($offer, $proposed), 'reason' => $revision->reason, 'by_user_id' => $actor->id,
            ]);

            return $revision;
        });
    }

    /**
     * Releases a pending revision — the approval step, needing offers.release like the original
     * release. The revised terms become the offer's terms; the previous release is kept as a
     * superseded revision. The offer stays Released and awaits the candidate's answer again.
     */
    public function releaseRevision(OfferRevision $revision, User $actor, ?string $remarks = null): OfferRevision
    {
        $offer = $revision->offer;

        if (! $actor->can('release', $offer)) {
            throw new DomainException('Releasing a revised offer requires the offers.release permission.');
        }

        return DB::transaction(fn (): OfferRevision => LifecycleGuard::allow(function () use ($revision, $offer, $actor, $remarks): OfferRevision {
            $current = OfferRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            $offer->refresh();

            if ($current->status !== OfferRevisionStatus::Pending) {
                throw new DomainException("This revision is already {$current->status->label()}.");
            }

            if ($offer->status !== OfferStatus::Released) {
                throw new DomainException("The offer is no longer released (it is {$offer->status->label()}); cancel this revision instead.");
            }

            $terms = collect(OfferRevision::TERMS)->mapWithKeys(fn (string $term) => [$term => $current->getRawOriginal($term)])->all();
            $changed = $this->changedTerms($offer, $terms);

            $offer->revisions()->where('status', OfferRevisionStatus::Released->value)->get()
                ->each(fn (OfferRevision $previous) => $previous->update(['status' => OfferRevisionStatus::Superseded]));
            $offer->forceFill($terms)->save();
            $current->update(['status' => OfferRevisionStatus::Released, 'decided_by' => $actor->id, 'decided_at' => now()]);

            $offer->statusHistory()->create([
                'from_status' => OfferStatus::Released,
                'to_status' => OfferStatus::Released,
                'changed_by' => $actor->employee_id,
                'remarks' => "Revision {$current->revision} released: {$current->reason}".(filled($remarks) ? " — {$remarks}" : ''),
            ]);

            $this->letters->issue($offer->refresh(), $current, $actor->employee_id);

            AuditLog::record($offer, 'offer_revision_released', ['revision' => $current->revision - 1], [
                'revision' => $current->revision, 'changed_terms' => $changed, 'by_user_id' => $actor->id,
            ]);

            OfferRevisionReleased::dispatch($offer->id, $current->id, $current->revision, $actor->id);

            return $current;
        }));
    }

    public function cancelRevision(OfferRevision $revision, User $actor, string $reason): OfferRevision
    {
        if (! $actor->can('update', $revision->offer)) {
            throw new DomainException('You are not allowed to cancel this revision.');
        }

        if (trim($reason) === '') {
            throw new DomainException('A reason is required to cancel a revision.');
        }

        return DB::transaction(function () use ($revision, $actor, $reason): OfferRevision {
            $current = OfferRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== OfferRevisionStatus::Pending) {
                throw new DomainException("This revision is already {$current->status->label()}.");
            }

            $current->update(['status' => OfferRevisionStatus::Cancelled, 'decided_by' => $actor->id, 'decided_at' => now()]);
            AuditLog::record($current->offer, 'offer_revision_cancelled', null, ['revision' => $current->revision, 'reason' => mb_substr(trim($reason), 0, 255), 'by_user_id' => $actor->id]);

            return $current;
        });
    }

    /**
     * Names of the terms that differ — never their values (compensation stays out of the audit log).
     *
     * @param  array<string, mixed>  $terms
     * @return array<int, string>
     */
    private function changedTerms(Offer $offer, array $terms): array
    {
        return collect($terms)
            ->filter(fn (mixed $value, string $term) => (string) $value !== (string) $offer->getRawOriginal($term))
            ->keys()
            ->values()
            ->all();
    }

    private function withdrawOtherOpenOffers(Offer $accepted, ?Employee $actor): void
    {
        Offer::query()
            ->where('candidate_application_id', $accepted->candidate_application_id)
            ->whereKeyNot($accepted->id)
            ->whereIn('status', [OfferStatus::Draft->value, OfferStatus::Initiated->value, OfferStatus::Released->value])
            ->get()
            ->each(fn (Offer $other) => $this->moveTo($other, OfferStatus::Withdrawn, $actor, "Withdrawn: offer {$accepted->offer_code} was accepted"));
    }

    /**
     * Released offers whose validity (`offer_expiry`) ended before today are moved to Expired
     * through the normal transition path, so each one gets its own history row.
     */
    public function expireLapsedOffers(): int
    {
        $lapsed = Offer::query()
            ->where('status', OfferStatus::Released)
            ->whereNotNull('offer_expiry')
            ->whereDate('offer_expiry', '<', today()->toDateString())
            ->get();

        // Phase 8.7 (D8.7-011): one offer that cannot expire never stops the others.
        $expired = 0;

        foreach ($lapsed as $offer) {
            try {
                $this->moveTo($offer, OfferStatus::Expired, remarks: 'Offer validity lapsed');
                $expired++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $expired;
    }

    /**
     * Releasing is a separate, higher-trust permission than managing offers (Section 27).
     */
    public function canRelease(?Employee $actor): bool
    {
        return (bool) $actor?->user?->can('offers.release');
    }

    /**
     * @return array<int, OfferStatus>
     */
    public function allowedNextStatuses(Offer $offer): array
    {
        return array_map(OfferStatus::from(...), self::ALLOWED_TRANSITIONS[$offer->status->value]);
    }

    private function notifyStatusChange(Offer $offer, OfferStatus $to): void
    {
        $application = $offer->candidateApplication;
        $recruiter = $application->recruiter;
        $candidateName = $application->candidate->full_name;
        $url = OfferResource::getUrl('edit', ['record' => $offer]);
        // Phase 8.7: one alert per transition — a retried request never repeats it, while a later
        // release of a revised offer (a new history row) still alerts.
        $transition = $offer->statusHistory()->count();

        match ($to) {
            OfferStatus::Released => $this->notifications->alert(
                $recruiter?->user,
                'Offers',
                'Offer released',
                "The offer for {$candidateName} has been released and is awaiting a response.",
                'info',
                $url,
                "offer-released-{$offer->id}-{$transition}",
            ),
            OfferStatus::Accepted => $this->notifications->alert(
                $recruiter?->user,
                'Offers',
                'Offer accepted',
                "{$candidateName} has accepted their offer.",
                'success',
                $url,
                "offer-accepted-{$offer->id}-{$transition}",
            ),
            OfferStatus::Rejected => $this->notifications->alert(
                $recruiter?->user,
                'Offers',
                'Offer rejected',
                "{$candidateName} has rejected their offer.",
                'danger',
                $url,
                "offer-rejected-{$offer->id}-{$transition}",
            ),
            default => null,
        };
    }

    private function syncApplicationStage(Offer $offer, OfferStatus $to, ?Employee $actor, ?RecruitmentRejectionReason $rejectionReason): void
    {
        $application = $offer->candidateApplication;

        match ($to) {
            OfferStatus::Initiated => $this->stageTransitions->transitionTo($application, CandidateStage::OfferInitiated, $actor),
            OfferStatus::Released => $this->stageTransitions->transitionTo($application, CandidateStage::OfferReleased, $actor),
            OfferStatus::Accepted => $this->stageTransitions->transitionTo($application, CandidateStage::OfferAccepted, $actor),
            OfferStatus::Rejected => $this->stageTransitions->reject($application, $rejectionReason, $actor, 'Offer rejected'),
            default => null,
        };
    }
}
