<?php

namespace App\Services\Outcomes;

use App\Enums\JoiningStatus;
use App\Enums\OutcomeCaptureMode;
use App\Enums\OutcomeConfidence;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeType;
use App\Models\CandidateJoining;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Offer;
use App\Models\OfferStatusHistory;

/**
 * Outcome Loop™ (Phase 8.2): deterministic outcome rules — application code, never AI. Each rule
 * reads its source of truth (joining record, offer status history, the immutable hiring snapshot)
 * and records the outcome with provenance through OutcomeService, idempotently.
 */
class OutcomeCalculator
{
    public function __construct(private readonly OutcomeService $outcomes) {}

    /**
     * Joined / No-show / Dropout, from the joining record only — never the pipeline stage.
     */
    public function joining(CandidateJoining $joining, OutcomeCaptureMode $mode = OutcomeCaptureMode::ObservedGoingForward): ?HiringOutcome
    {
        $type = match ($joining->status) {
            JoiningStatus::Joined => OutcomeType::Joined,
            JoiningStatus::NoShow => OutcomeType::NoShow,
            JoiningStatus::Dropout => OutcomeType::Dropout,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $joining->loadMissing(['candidateApplication', 'dropoutReason']);
        $reason = $joining->dropoutReason;

        return $this->outcomes->record($type, "joining:{$joining->id}", [
            'result' => OutcomeResult::Occurred,
            'confidence' => OutcomeConfidence::High,
            'source' => $joining,
            'observed_at' => $type === OutcomeType::Joined ? ($joining->actual_doj ?? $joining->updated_at) : $joining->updated_at,
            'candidate_joining_id' => $joining->id,
            'candidate_application_id' => $joining->candidate_application_id,
            'requisition_id' => $joining->candidateApplication?->requisition_id,
            'details' => $reason !== null ? ['reason' => $reason->name, 'reason_category' => $reason->category?->value] : null,
        ], $mode);
    }

    /**
     * One outcome per offer status reached (released, accepted, rejected, expired, withdrawn),
     * from the immutable offer status history. Release never implies acceptance; acceptance never
     * implies joining.
     *
     * @return array<int, HiringOutcome>
     */
    public function offer(Offer $offer, OutcomeCaptureMode $mode = OutcomeCaptureMode::ObservedGoingForward): array
    {
        $offer->loadMissing(['candidateApplication', 'statusHistory']);

        return $offer->statusHistory->sortBy('id')
            ->map(fn (OfferStatusHistory $history) => [$history, OutcomeType::forOfferStatus($history->to_status)])
            ->filter(fn (array $pair) => $pair[1] !== null)
            ->unique(fn (array $pair) => $pair[1]->value)
            ->map(fn (array $pair) => $this->outcomes->record($pair[1], "offer:{$offer->id}:{$pair[1]->value}", [
                'result' => OutcomeResult::Occurred,
                'confidence' => OutcomeConfidence::High,
                'source' => $pair[0],
                'observed_at' => $pair[0]->created_at,
                'offer_id' => $offer->id,
                'candidate_application_id' => $offer->candidate_application_id,
                'requisition_id' => $offer->candidateApplication?->requisition_id,
            ], $mode))
            ->values()
            ->all();
    }

    /**
     * Time to hire and time in stage, from the immutable snapshot taken at the join.
     *
     * @return array<int, HiringOutcome>
     */
    public function process(HiringOutcomeSnapshot $snapshot, OutcomeCaptureMode $mode = OutcomeCaptureMode::ObservedGoingForward): array
    {
        $links = [
            'hiring_outcome_snapshot_id' => $snapshot->id,
            'candidate_application_id' => $snapshot->candidate_application_id,
            'requisition_id' => $snapshot->requisition_id,
            'candidate_joining_id' => $snapshot->candidate_joining_id,
            'source' => $snapshot,
        ];
        $timeToHire = $snapshot->facts['time_to_hire'] ?? null;
        $stages = $snapshot->facts['stage_days'] ?? [];

        return [
            $this->outcomes->record(OutcomeType::TimeToHire, "snapshot:{$snapshot->id}:time_to_hire", [
                ...$links,
                'result' => $snapshot->time_to_hire_days !== null ? OutcomeResult::Measured : OutcomeResult::Unknown,
                'confidence' => OutcomeConfidence::High,
                'value' => $snapshot->time_to_hire_days,
                'unit' => 'days',
                'observed_at' => $snapshot->joined_on,
                'observation_start' => $timeToHire['start_date'] ?? null,
                'observation_end' => $snapshot->joined_on,
                'details' => $timeToHire,
            ], $mode),
            $this->outcomes->record(OutcomeType::TimeInStage, "snapshot:{$snapshot->id}:time_in_stage", [
                ...$links,
                'result' => $stages !== [] ? OutcomeResult::Measured : OutcomeResult::Unknown,
                'confidence' => OutcomeConfidence::High,
                'value' => $stages !== [] ? array_sum($stages) : null,
                'unit' => 'days',
                'observed_at' => $snapshot->joined_on,
                'observation_end' => $snapshot->joined_on,
                'details' => ['stage_days' => $stages],
            ], $mode),
        ];
    }
}
