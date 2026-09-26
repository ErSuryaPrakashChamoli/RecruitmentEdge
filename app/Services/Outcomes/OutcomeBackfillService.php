<?php

namespace App\Services\Outcomes;

use App\Enums\OutcomeCaptureMode;
use App\Models\CandidateJoining;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Offer;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Outcome Loop™ (Phase 8.2): reconstructs deterministic outcomes of past hiring from the records of
 * truth — joining status, offer status history, stage history — labelled BACKFILLED_DETERMINISTIC.
 *
 * - Never retention: no status observation is created for the past, and AuditLog history is never
 *   read as evidence. Checkpoints that passed before a backfilled snapshot stay "not observed".
 * - Additive and idempotent: an outcome already recorded (by events, the daily evaluation or an
 *   earlier backfill) is left as it is, so running twice changes nothing.
 * - A dry run (the default) only counts.
 */
class OutcomeBackfillService
{
    /**
     * @var array<int, string>
     */
    public const array STEPS = ['joining_outcomes', 'snapshots', 'process_outcomes', 'offer_outcomes'];

    public function __construct(
        private readonly OutcomeEvaluator $evaluator,
        private readonly OutcomeCalculator $calculator,
        private readonly HiringSnapshotService $snapshots,
    ) {}

    /**
     * @return array<string, int> per step: records due (dry run) or processed
     */
    public function run(bool $execute, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $requisitionId = null, int $batch = 200, ?Closure $onStep = null): array
    {
        $mode = OutcomeCaptureMode::BackfilledDeterministic;
        $steps = [
            'joining_outcomes' => [fn () => $this->evaluator->pendingJoinings($from, $to, $requisitionId), fn (CandidateJoining $joining) => $this->calculator->joining($joining, $mode)],
            'snapshots' => [fn () => $this->evaluator->joinedWithoutSnapshot($from, $to, $requisitionId), fn (CandidateJoining $joining) => $this->snapshots->captureForJoining($joining, $mode)],
            'process_outcomes' => [fn () => $this->evaluator->snapshotsWithoutProcessOutcomes($requisitionId)->where('capture_mode', $mode->value), fn (HiringOutcomeSnapshot $snapshot) => $this->calculator->process($snapshot, $mode)],
            'offer_outcomes' => [fn () => $this->evaluator->pendingOffers($from, $to, $requisitionId), fn (Offer $offer) => $this->calculator->offer($offer, $mode)],
        ];
        $counts = [];

        foreach ($steps as $step => [$query, $handle]) {
            /** @var Builder<Model> $pending */
            $pending = $query();
            $total = $pending->count();
            $tick = $onStep !== null ? $onStep($step, $total) : null;

            if (! $execute) {
                $counts[$step] = $total;

                continue;
            }

            $counts[$step] = 0;
            $pending->chunkById(max(1, $batch), function ($models) use ($handle, $tick, &$counts, $step): void {
                foreach ($models as $model) {
                    $handle($model);
                    $counts[$step]++;
                    $tick?->__invoke();
                }
            });
        }

        return $counts;
    }

    /**
     * Current outcomes by how they were captured — backfilled history is always reported apart
     * from outcomes observed going forward.
     *
     * @return array<string, int>
     */
    public function captureModeTotals(): array
    {
        $totals = HiringOutcome::query()->current()->selectRaw('capture_mode, count(*) as total')->groupBy('capture_mode')->pluck('total', 'capture_mode');

        return collect(OutcomeCaptureMode::cases())->mapWithKeys(fn (OutcomeCaptureMode $mode) => [$mode->value => (int) ($totals[$mode->value] ?? 0)])->all();
    }
}
