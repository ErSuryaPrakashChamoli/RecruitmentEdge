<?php

namespace App\Services;

use App\Enums\IncentiveCalculationStatus;
use App\Enums\IncentivePayoutType;
use App\Enums\IncentiveSlabUpgradeMode;
use App\Enums\IncentiveTriggerEvent;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentIncentiveSlab;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Section 31's incentive calculator. For a given trigger event, finds every active, in-scope
 * incentive rule and prices the occurrence (e.g. one joining) by the rule's payout type:
 *
 * - Fixed: the rule's `fixed_amount`, no slab.
 * - Slab by count: the slab matching the recruiter's count of this rule's occurrences in the month.
 * - Slab by achievement %: the slab matching the recruiter's achievement on the rule's metric,
 *   reusing TargetResolutionService/RecruiterDailyMetricsService from the Performance engine (the
 *   same "achievement %" concept, not a second implementation of it).
 *
 * For slab rules, `slab_upgrade_mode` decides what reaching a higher band means: Incremental prices
 * only the occurrence at hand, Retroactive also re-prices the month's other live calculations of the
 * rule (see repriceSiblings()).
 *
 * Duplicate-safe by construction: (rule, application, period) is unique at the DB level, and a
 * calculation already at Approved or later is never edited by a recalculation (Section 28) — a
 * retroactive upgrade reaches those only through a top-up adjustment.
 */
class RecruiterIncentiveCalculator
{
    /**
     * Reason prefix of the top-up adjustments written by a retroactive slab upgrade.
     */
    public const RETROACTIVE_ADJUSTMENT_REASON = 'Retroactive slab upgrade';

    /**
     * Statuses whose amount a recalculation may still rewrite in place.
     */
    private const RECALCULABLE = [
        IncentiveCalculationStatus::Calculated,
        IncentiveCalculationStatus::PendingVerification,
    ];

    /**
     * Calculations that no longer count toward a slab count or get re-priced.
     */
    private const EXCLUDED = [
        IncentiveCalculationStatus::Rejected,
        IncentiveCalculationStatus::Reversed,
    ];

    public function __construct(
        private readonly TargetResolutionService $targets,
        private readonly RecruiterDailyMetricsService $metrics,
        private readonly IncentiveApprovalService $approvals,
    ) {}

    /**
     * The primary, automatically-wired path (Section 25): a candidate has just been marked
     * Joined. See CandidateJoiningService::markJoined().
     *
     * @return Collection<int, RecruiterIncentiveCalculation>
     */
    public function calculateForJoining(CandidateJoining $joining): Collection
    {
        $eventDate = $joining->actual_doj ?? $joining->expected_doj;

        return $this->calculate($joining->candidateApplication, IncentiveTriggerEvent::Joining, $eventDate);
    }

    /**
     * Available for Selection-triggered rules. Not yet wired automatically into
     * StageTransitionService — call this manually (e.g. via a Filament action) until a future
     * phase decides how broadly to hook every stage transition.
     *
     * @return Collection<int, RecruiterIncentiveCalculation>
     */
    public function calculateForSelection(CandidateApplication $application, ?CarbonInterface $eventDate = null): Collection
    {
        return $this->calculate($application, IncentiveTriggerEvent::Selection, $eventDate ?? now());
    }

    /**
     * Available for OfferAccepted-triggered rules — same caveat as calculateForSelection().
     *
     * @return Collection<int, RecruiterIncentiveCalculation>
     */
    public function calculateForOfferAcceptance(CandidateApplication $application, ?CarbonInterface $eventDate = null): Collection
    {
        return $this->calculate($application, IncentiveTriggerEvent::OfferAccepted, $eventDate ?? now());
    }

    /**
     * @return Collection<int, RecruiterIncentiveCalculation>
     */
    private function calculate(CandidateApplication $application, IncentiveTriggerEvent $event, CarbonInterface $eventDate): Collection
    {
        $recruiter = $application->recruiter;

        $rules = RecruitmentIncentiveRule::query()
            ->with('slabs')
            ->where('trigger_event', $event)
            ->where('is_active', true)
            ->where('effective_from', '<=', $eventDate)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $eventDate))
            ->get()
            // Recruiter/department/designation/location scope, matched against the recruiter's own employee record.
            ->filter(fn (RecruitmentIncentiveRule $rule) => $rule->appliesTo($recruiter))
            ->filter(fn (RecruitmentIncentiveRule $rule) => $rule->employment_type === null
                || $rule->employment_type === $application->requisition->employment_type);

        return $rules
            ->map(fn (RecruitmentIncentiveRule $rule) => $this->calculateForRule($rule, $application, $eventDate))
            ->filter()
            ->values();
    }

    private function calculateForRule(RecruitmentIncentiveRule $rule, CandidateApplication $application, CarbonInterface $eventDate): ?RecruiterIncentiveCalculation
    {
        $recruiter = $application->recruiter;
        $periodStart = $eventDate->copy()->startOfMonth();
        $periodEnd = $eventDate->copy()->endOfMonth();

        $existing = RecruiterIncentiveCalculation::query()
            ->where('incentive_rule_id', $rule->id)
            ->where('candidate_application_id', $application->id)
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->first();

        if ($existing !== null && ! in_array($existing->status, self::RECALCULABLE, true)) {
            return $existing;
        }

        $achievement = null;

        if ($rule->payout_type === IncentivePayoutType::SlabByAchievement && $rule->achievement_metric !== null) {
            $target = $this->targets->resolveForRange($recruiter, $rule->achievement_metric, $periodStart, $periodEnd);
            $actual = $this->metrics->actualFor($recruiter, $rule->achievement_metric, $periodStart, $periodEnd);
            $achievement = ($target !== null && $target > 0) ? round($actual / $target * 100, 2) : 0.0;
        }

        $occurrenceCount = $rule->payout_type === IncentivePayoutType::SlabByCount
            ? $this->occurrenceCountFor($rule, $recruiter->id, $periodStart, $periodEnd, $existing)
            : null;

        [$slab, $amount] = $this->priceFor($rule, $achievement, $occurrenceCount);

        if ($amount === null) {
            return null;
        }

        $retentionDueAt = $rule->retention_days !== null ? $eventDate->copy()->addDays($rule->retention_days) : null;
        $status = ($retentionDueAt !== null && $retentionDueAt->isFuture())
            ? IncentiveCalculationStatus::Calculated
            : IncentiveCalculationStatus::PendingVerification;

        $attributes = [
            'incentive_slab_id' => $slab?->id,
            'employee_id' => $recruiter->id,
            'candidate_id' => $application->candidate_id,
            'achievement' => $achievement,
            'occurrence_count' => $occurrenceCount,
            'amount' => $amount,
            'retention_due_at' => $retentionDueAt,
            'calculated_at' => now(),
        ];

        // Status is never written directly here: IncentiveApprovalService owns every status change
        // and its trail row (creation, the no-retention move to Pending Verification, and any
        // status a recalculation re-derives).
        return DB::transaction(function () use ($existing, $attributes, $status, $rule, $application, $periodStart, $periodEnd, $slab, $achievement, $occurrenceCount): RecruiterIncentiveCalculation {
            if ($existing !== null) {
                $existing->update($attributes);

                $calculation = $this->approvals->applyRecalculatedStatus($existing, $status);
            } else {
                $calculation = RecruiterIncentiveCalculation::query()->create([
                    'incentive_rule_id' => $rule->id,
                    'candidate_application_id' => $application->id,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'status' => IncentiveCalculationStatus::Calculated,
                    ...$attributes,
                ]);

                $this->approvals->recordCalculated($calculation);

                if ($status === IncentiveCalculationStatus::PendingVerification) {
                    $this->approvals->submitForVerification($calculation, remarks: 'No retention hold');
                }
            }

            if ($slab !== null && $rule->slab_upgrade_mode === IncentiveSlabUpgradeMode::Retroactive) {
                $this->repriceSiblings($rule, $calculation, $slab, $achievement, $occurrenceCount);
            }

            return $calculation;
        });
    }

    /**
     * The count a Slab-by-count rule prices with. Incremental mode uses this occurrence's position in
     * the month (so earlier occurrences keep their band on recalculation); Retroactive mode uses the
     * month's total so far. Rejected and Reversed calculations do not count.
     */
    private function occurrenceCountFor(
        RecruitmentIncentiveRule $rule,
        int $employeeId,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        ?RecruiterIncentiveCalculation $existing,
    ): int {
        $counted = RecruiterIncentiveCalculation::query()
            ->where('incentive_rule_id', $rule->id)
            ->where('employee_id', $employeeId)
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->whereNotIn('status', self::EXCLUDED);

        if ($existing === null) {
            return $counted->count() + 1;
        }

        return $rule->slab_upgrade_mode === IncentiveSlabUpgradeMode::Retroactive
            ? $counted->count()
            : $counted->where('id', '<=', $existing->id)->count();
    }

    /**
     * A Slab-by-achievement rule without a metric is the legacy flat-amount setup: a single
     * catch-all band, matched at 0.
     *
     * @return array{0: RecruitmentIncentiveSlab|null, 1: float|null}
     */
    private function priceFor(RecruitmentIncentiveRule $rule, ?float $achievement, ?int $occurrenceCount): array
    {
        if ($rule->payout_type === IncentivePayoutType::Fixed) {
            return [null, $rule->fixed_amount !== null ? (float) $rule->fixed_amount : null];
        }

        $basis = $rule->payout_type === IncentivePayoutType::SlabByCount
            ? (float) $occurrenceCount
            : ($achievement ?? 0.0);

        $slab = $rule->slabs->first(fn (RecruitmentIncentiveSlab $slab) => $slab->matches($basis));

        return [$slab, $slab !== null ? (float) $slab->amount : null];
    }

    /**
     * Retroactive upgrade: re-prices the month's other live calculations of this rule for the same
     * recruiter at the slab just reached. Calculated/Pending Verification ones are updated in place;
     * approved-or-later ones get a top-up adjustment instead, because their amount is never edited.
     * Nothing is ever priced down, and earlier top-ups count toward the new price so none repeat.
     */
    private function repriceSiblings(
        RecruitmentIncentiveRule $rule,
        RecruiterIncentiveCalculation $calculation,
        RecruitmentIncentiveSlab $slab,
        ?float $achievement,
        ?int $occurrenceCount,
    ): void {
        $newAmount = (float) $slab->amount;

        $siblings = RecruiterIncentiveCalculation::query()
            ->where('incentive_rule_id', $rule->id)
            ->where('employee_id', $calculation->employee_id)
            ->whereDate('period_start', $calculation->period_start)
            ->whereDate('period_end', $calculation->period_end)
            ->whereKeyNot($calculation->getKey())
            ->whereNotIn('status', self::EXCLUDED)
            ->get();

        foreach ($siblings as $sibling) {
            if (in_array($sibling->status, self::RECALCULABLE, true)) {
                if ((float) $sibling->amount < $newAmount) {
                    $sibling->update([
                        'incentive_slab_id' => $slab->id,
                        'amount' => $newAmount,
                        'achievement' => $achievement,
                        'occurrence_count' => $occurrenceCount,
                    ]);
                }

                continue;
            }

            $pricedAt = (float) $sibling->amount + (float) $sibling->adjustments()
                ->where('reason', 'like', self::RETROACTIVE_ADJUSTMENT_REASON.'%')
                ->sum('amount_delta');

            if ($newAmount > $pricedAt) {
                $this->approvals->adjust(
                    $sibling,
                    $newAmount - $pricedAt,
                    self::RETROACTIVE_ADJUSTMENT_REASON.' to '.$slab->bandLabel($rule).' (₹'.number_format($newAmount, 2).')',
                );
            }
        }
    }
}
