<?php

namespace App\Services\Outcomes;

use App\Enums\EmployeeStatus;
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
use Carbon\CarbonInterface;

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

    /**
     * A status observation at a checkpoint after joining (30 / 90 / 180 days) — going forward only.
     *
     * 1. A separation record on or before the checkpoint is authoritative: the first checkpoint it
     *    precedes is SeparatedBeforeCheckpoint (high confidence); later checkpoints are NotObserved
     *    (the employee never reached them).
     * 2. Otherwise the employee status is observed once, on the day the check runs: Active or
     *    Inactive (medium confidence; low if the check ran late). Inactive is not treated as an exit.
     *    An observation is never re-observed later — only separation evidence can revise it.
     * 3. A checkpoint that passed before the snapshot was taken (a backfilled hire), or a hire with
     *    no employee record, is NotObserved — never inferred.
     *
     * Phase 8.4 (compatibility, same results for existing data): the separation considered is the
     * one that ended *this* employment (Employee::separationForEmploymentFrom — cancelled
     * separations and those from before a rehire never count), and an employee whose status is now
     * Separated was still employed at a checkpoint before that separation's date, so it observes as
     * Active — exactly what the unchanged status showed before 8.4.
     */
    public function statusObservation(HiringOutcomeSnapshot $snapshot, OutcomeType $type, ?CarbonInterface $today = null): ?HiringOutcome
    {
        $window = $type->windowDays();
        $today = ($today ?? now())->copy()->startOfDay();

        if ($window === null) {
            return null;
        }

        $checkpoint = $snapshot->joined_on->copy()->addDays($window)->startOfDay();

        if ($checkpoint->gt($today)) {
            return null;
        }

        $snapshot->loadMissing('employee.separations');
        $employee = $snapshot->employee;
        $separation = $employee?->separationForEmploymentFrom($snapshot->joined_on);
        $key = "snapshot:{$snapshot->id}:{$type->value}";
        $base = [
            'hiring_outcome_snapshot_id' => $snapshot->id,
            'candidate_application_id' => $snapshot->candidate_application_id,
            'requisition_id' => $snapshot->requisition_id,
            'candidate_joining_id' => $snapshot->candidate_joining_id,
            'employee_id' => $employee?->id,
            'observation_start' => $snapshot->joined_on,
            'observation_end' => $checkpoint,
        ];

        if ($separation !== null && $separation->separation_date->copy()->startOfDay()->lte($checkpoint)) {
            $previous = collect(config('outcomes.status_observation_days', []))->filter(fn (int $days) => $days < $window)->max();
            $previousCheckpoint = $previous !== null ? $snapshot->joined_on->copy()->addDays($previous)->startOfDay() : null;
            $separatedInThisWindow = $previousCheckpoint === null || $separation->separation_date->copy()->startOfDay()->gt($previousCheckpoint);

            return $this->outcomes->record($type, $key, [
                ...$base,
                'result' => $separatedInThisWindow ? OutcomeResult::SeparatedBeforeCheckpoint : OutcomeResult::NotObserved,
                'confidence' => OutcomeConfidence::High,
                'source' => $separation,
                'observed_at' => now(),
                'details' => [
                    'checkpoint' => $checkpoint->toDateString(),
                    'separation_date' => $separation->separation_date->toDateString(),
                    'separation_reason' => $separation->separation_reason->value,
                    'reason' => $separatedInThisWindow ? 'separation_record' : 'separated_before_earlier_checkpoint',
                ],
            ]);
        }

        if ($existing = HiringOutcome::query()->where('dedupe_key', $key)->current()->first()) {
            return $existing;
        }

        if ($employee === null || $snapshot->captured_at->copy()->startOfDay()->gt($checkpoint)) {
            return $this->outcomes->record($type, $key, [
                ...$base,
                'result' => OutcomeResult::NotObserved,
                'confidence' => OutcomeConfidence::Low,
                'source' => $snapshot,
                'observed_at' => now(),
                'details' => ['checkpoint' => $checkpoint->toDateString(), 'reason' => $employee === null ? 'no_employee_record' : 'checkpoint_before_observation_started'],
            ]);
        }

        $daysLate = (int) $checkpoint->diffInDays($today);

        return $this->outcomes->record($type, $key, [
            ...$base,
            'result' => in_array($employee->status, [EmployeeStatus::Active, EmployeeStatus::Separated], true) ? OutcomeResult::Active : OutcomeResult::Inactive,
            'confidence' => $daysLate > (int) config('outcomes.status_observation_grace_days', 7) ? OutcomeConfidence::Low : OutcomeConfidence::Medium,
            'source' => $employee,
            'observed_at' => now(),
            'details' => ['checkpoint' => $checkpoint->toDateString(), 'observed_on' => $today->toDateString(), 'days_after_checkpoint' => $daysLate, 'method' => 'employee_status_observation'],
        ]);
    }
}
