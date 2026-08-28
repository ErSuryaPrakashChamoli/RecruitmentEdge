<?php

namespace App\Services;

use App\Enums\CandidateStage;
use App\Enums\OfferStatus;
use App\Events\OfferAccepted;
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

    public function __construct(private readonly StageTransitionService $stageTransitions) {}

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

            return $offer;
        });
    }

    /**
     * @return array<int, OfferStatus>
     */
    public function allowedNextStatuses(Offer $offer): array
    {
        return array_map(OfferStatus::from(...), self::ALLOWED_TRANSITIONS[$offer->status->value]);
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
