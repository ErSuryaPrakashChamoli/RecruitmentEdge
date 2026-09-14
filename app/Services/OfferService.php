<?php

namespace App\Services;

use App\Enums\CandidateStage;
use App\Enums\OfferStatus;
use App\Events\OfferAccepted;
use App\Filament\Resources\Offers\OfferResource;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\RecruitmentRejectionReason;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The only code path allowed to change an offer's status. Every change is written atomically with
 * a permanent `offer_status_histories` row — offers are financially sensitive (Section 27/28) and
 * must never be silently overwritten.
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

    public function __construct(
        private readonly StageTransitionService $stageTransitions,
        private readonly NotificationDispatchService $notifications,
    ) {}

    /**
     * Creates an offer together with its initial (null -> status) history row, so the trail starts
     * at creation rather than at the first transition.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?Employee $actor = null): Offer
    {
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
        $from = $offer->status;

        if (! in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value], true)) {
            throw new DomainException("Cannot move an offer from {$from->label()} to {$to->label()}.");
        }

        if ($to === OfferStatus::Released && ! $this->canRelease($actor)) {
            throw new DomainException('Releasing an offer requires the offers.release permission.');
        }

        if ($to === OfferStatus::Rejected && $rejectionReason === null) {
            throw new DomainException('A rejection reason is required to reject an offer.');
        }

        return DB::transaction(function () use ($offer, $from, $to, $actor, $remarks, $rejectionReason): Offer {
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
                OfferAccepted::dispatch($offer);
            }

            $this->notifyStatusChange($offer, $to);

            return $offer;
        });
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

        $lapsed->each(fn (Offer $offer) => $this->moveTo($offer, OfferStatus::Expired, remarks: 'Offer validity lapsed'));

        return $lapsed->count();
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

        match ($to) {
            OfferStatus::Released => $this->notifications->alert(
                $recruiter?->user,
                'Offers',
                'Offer released',
                "The offer for {$candidateName} has been released and is awaiting a response.",
                'info',
                $url,
            ),
            OfferStatus::Accepted => $this->notifications->alert(
                $recruiter?->user,
                'Offers',
                'Offer accepted',
                "{$candidateName} has accepted their offer.",
                'success',
                $url,
            ),
            OfferStatus::Rejected => $this->notifications->alert(
                $recruiter?->user,
                'Offers',
                'Offer rejected',
                "{$candidateName} has rejected their offer.",
                'danger',
                $url,
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
