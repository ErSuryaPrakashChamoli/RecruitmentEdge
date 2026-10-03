<?php

namespace App\Services;

use App\Enums\CandidateStage;
use App\Enums\IncentiveBeneficiary;
use App\Enums\IncentiveCalculationStatus;
use App\Enums\IncentivePayoutType;
use App\Enums\IncentiveSlabUpgradeMode;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\EmployeeReferral;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentIncentiveSlab;
use App\Models\User;
use App\Services\Lifecycle\RowLock;
use Carbon\CarbonInterface;
use DomainException;
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
 *
 * Phase 8.9 (P89-DQ-002): pricing one rule for one occurrence runs in a single transaction that
 * first locks the rule row, so concurrent occurrences of the same rule are priced one after the
 * other. Inside it the existing calculation is locked and re-read (an Approved one is never
 * reverted by a stale recalculation), and occurrence counts, siblings and earlier top-ups are read
 * with locking reads, so two joinings in the same month never share a slab position and a
 * retroactive top-up is never written twice.
 *
 * Phase 8.10 (P810-DI-01): each trigger is priced only once its lifecycle event has happened
 * (Selected reached, an accepted offer, a Joined joining), and one occurrence — a rule and an
 * application — has one calculation whatever the month it is priced in, checked under the same rule
 * lock. The manual path is calculateManually(): permission, hierarchy, the application lock and an
 * audit row for every request, refused or not. Formulas are unchanged.
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
        private readonly HierarchyService $hierarchy,
    ) {}

    /**
     * Phase 8.10 (P810-DI-01): the manual "Calculate Incentives" action. The actor needs
     * incentives.calculate and the application's recruiter in their hierarchy. The application is
     * locked first (the lifecycle lock order), so its stage, offer and joining cannot change between
     * the trigger's precondition check and the pricing. Every request is audited, refusals included.
     *
     * @return Collection<int, RecruiterIncentiveCalculation>
     *
     * @throws DomainException when the actor may not calculate it or the trigger's event has not happened
     */
    public function calculateManually(CandidateApplication $application, IncentiveTriggerEvent $event, User $actor): Collection
    {
        try {
            if (! $actor->can('incentives.calculate') || ! $this->hierarchy->canView($actor, $application->recruiter)) {
                throw new DomainException('You cannot calculate incentives for this application.');
            }

            $results = DB::transaction(function () use ($application, $event): Collection {
                RowLock::key(CandidateApplication::class, $application->id);
                $application->refresh();

                return match ($event) {
                    IncentiveTriggerEvent::Selection => $this->calculateForSelection($application),
                    IncentiveTriggerEvent::OfferAccepted => $this->calculateForOfferAcceptance($application),
                    IncentiveTriggerEvent::Joining => $this->calculateForJoining($application->joining ?? throw new DomainException('This application has no joining record.')),
                    IncentiveTriggerEvent::ReferralJoining => EmployeeReferral::query()
                        ->where('candidate_application_id', $application->id)
                        ->get()
                        ->flatMap(fn (EmployeeReferral $referral) => $this->calculateForReferralJoining($referral))
                        ->values(),
                };
            });
        } catch (DomainException $e) {
            AuditLog::record($application, 'incentive_calculation_refused', null, ['trigger_event' => $event->value, 'reason' => $e->getMessage(), 'by_user_id' => $actor->id]);

            throw $e;
        }

        AuditLog::record($application, 'incentive_calculation_requested', null, ['trigger_event' => $event->value, 'calculation_ids' => $results->pluck('id')->all(), 'by_user_id' => $actor->id]);

        return $results;
    }

    /**
     * The primary, automatically-wired path (Section 25): a candidate has just been marked
     * Joined. See CandidateJoiningService::markJoined().
     *
     * @return Collection<int, RecruiterIncentiveCalculation>
     */
    public function calculateForJoining(CandidateJoining $joining): Collection
    {
        if ($joining->status !== JoiningStatus::Joined) {
            throw new DomainException("Joining incentives are priced only once the candidate has joined (this joining is {$joining->status->label()}).");
        }

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
        if ($application->current_stage->order() < CandidateStage::Selected->order()) {
            throw new DomainException('Selection incentives are priced only for an application that has reached Selected.');
        }

        return $this->calculate($application, IncentiveTriggerEvent::Selection, $eventDate ?? now());
    }

    /**
     * Available for OfferAccepted-triggered rules — same caveat as calculateForSelection().
     *
     * @return Collection<int, RecruiterIncentiveCalculation>
     */
    public function calculateForOfferAcceptance(CandidateApplication $application, ?CarbonInterface $eventDate = null): Collection
    {
        if (! $application->offers()->where('status', OfferStatus::Accepted)->exists()) {
            throw new DomainException('Offer-acceptance incentives are priced only for an application with an accepted offer.');
        }

        return $this->calculate($application, IncentiveTriggerEvent::OfferAccepted, $eventDate ?? now());
    }

    /**
     * Referral bonus (Phase 4): prices ReferralJoining rules for the referring employee when a
     * referred candidate joins — the same rules, slabs, retention and approval trail as recruiter
     * incentives, only the beneficiary differs. Wired automatically by
     * ReferralService::syncFromApplication(); safe to re-run (same duplicate guard).
     *
     * @return Collection<int, RecruiterIncentiveCalculation>
     */
    public function calculateForReferralJoining(EmployeeReferral $referral): Collection
    {
        $application = $referral->candidateApplication;

        // Business eligibility (joined, not the application's own recruiter, …) is decided and
        // audited by ReferralService::referralIncentiveIneligibility(); this is the last guard.
        if ($application === null || ! $referral->incentive_eligible) {
            return collect();
        }

        // Phase 8.10 (P810-DI-02): the joining record, not the pipeline stage, says the candidate
        // joined, and dates the bonus when the referral carries no joining date.
        $joining = CandidateJoining::query()->where('candidate_application_id', $application->id)->first();

        if ($joining?->status !== JoiningStatus::Joined) {
            throw new DomainException('Referral bonuses are priced only once the referred candidate has joined.');
        }

        $eventDate = $referral->joining_date ?? $joining->actual_doj ?? $joining->expected_doj;

        return $this->calculate($application, IncentiveTriggerEvent::ReferralJoining, $eventDate, $referral->referrer, $referral);
    }

    /**
     * @param  Employee|null  $beneficiary  who earns the incentive; the application's recruiter unless given
     * @return Collection<int, RecruiterIncentiveCalculation>
     */
    private function calculate(CandidateApplication $application, IncentiveTriggerEvent $event, CarbonInterface $eventDate, ?Employee $beneficiary = null, ?EmployeeReferral $referral = null): Collection
    {
        $recruiter = $beneficiary ?? $application->recruiter;

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
            ->map(fn (RecruitmentIncentiveRule $rule) => $this->calculateForRule($rule, $application, $eventDate, $recruiter, $referral))
            ->filter()
            ->values();
    }

    private function calculateForRule(RecruitmentIncentiveRule $rule, CandidateApplication $application, CarbonInterface $eventDate, Employee $recruiter, ?EmployeeReferral $referral = null): ?RecruiterIncentiveCalculation
    {
        return DB::transaction(function () use ($rule, $application, $eventDate, $recruiter, $referral): ?RecruiterIncentiveCalculation {
            RowLock::key(RecruitmentIncentiveRule::class, $rule->id);

            return $this->priceUnderRuleLock($rule, $application, $eventDate, $recruiter, $referral);
        });
    }

    /**
     * Runs inside calculateForRule()'s transaction, holding the rule's row lock.
     */
    private function priceUnderRuleLock(RecruitmentIncentiveRule $rule, CandidateApplication $application, CarbonInterface $eventDate, Employee $recruiter, ?EmployeeReferral $referral): ?RecruiterIncentiveCalculation
    {
        $periodStart = $eventDate->copy()->startOfMonth();
        $periodEnd = $eventDate->copy()->endOfMonth();

        $existing = RecruiterIncentiveCalculation::query()
            ->where('incentive_rule_id', $rule->id)
            ->where('candidate_application_id', $application->id)
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->lockForUpdate()
            ->first();

        if ($existing !== null && ! in_array($existing->status, self::RECALCULABLE, true)) {
            return $existing;
        }

        // Phase 8.10 (P810-DI-01): already priced in another month (a later manual run prices at
        // that day) — the occurrence keeps its one calculation, untouched; never a second record.
        if ($existing === null) {
            $pricedInAnotherPeriod = RecruiterIncentiveCalculation::query()
                ->where('incentive_rule_id', $rule->id)
                ->where('candidate_application_id', $application->id)
                ->lockForUpdate()
                ->first();

            if ($pricedInAnotherPeriod !== null) {
                return $pricedInAnotherPeriod;
            }
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
            'beneficiary_type' => IncentiveBeneficiary::forTrigger($rule->trigger_event),
            'employee_referral_id' => $referral?->id,
            'candidate_id' => $application->candidate_id,
            'achievement' => $achievement,
            'occurrence_count' => $occurrenceCount,
            'amount' => $amount,
            // Phase 8.6 (D8.6-014): exactly what this price was computed from.
            'pricing_snapshot' => $this->pricingSnapshot($rule, $slab, $achievement, $occurrenceCount),
            'retention_due_at' => $retentionDueAt,
            'calculated_at' => now(),
        ];

        // Status is never written directly here: IncentiveApprovalService owns every status change
        // and its trail row (creation, the no-retention move to Pending Verification, and any
        // status a recalculation re-derives).
        if ($existing !== null) {
            $this->auditReprice($existing, $attributes);
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
            ->whereNotIn('status', self::EXCLUDED)
            ->sharedLock();

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
            ->lockForUpdate()
            ->get();

        foreach ($siblings as $sibling) {
            if (in_array($sibling->status, self::RECALCULABLE, true)) {
                if ((float) $sibling->amount < $newAmount) {
                    $repriced = [
                        'incentive_slab_id' => $slab->id,
                        'amount' => $newAmount,
                        'pricing_snapshot' => $this->pricingSnapshot($rule, $slab, $achievement, $occurrenceCount),
                        'achievement' => $achievement,
                        'occurrence_count' => $occurrenceCount,
                    ];
                    $this->auditReprice($sibling, $repriced);
                    $sibling->update($repriced);
                }

                continue;
            }

            $pricedAt = (float) $sibling->amount + (float) $sibling->adjustments()
                ->where('reason', 'like', self::RETROACTIVE_ADJUSTMENT_REASON.'%')
                ->sharedLock()
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

    /**
     * Phase 8.6 (D8.6-014): the rule, slab and basis a price came from, frozen on the calculation.
     *
     * @return array{rule: array<string, mixed>, slab: array<string, mixed>|null, basis: array{achievement: float|null, occurrence_count: int|null}, priced_at: string}
     */
    private function pricingSnapshot(RecruitmentIncentiveRule $rule, ?RecruitmentIncentiveSlab $slab, ?float $achievement, ?int $occurrenceCount): array
    {
        return [
            'rule' => [
                'id' => $rule->id,
                'name' => $rule->name,
                'trigger_event' => $rule->trigger_event?->value,
                'payout_type' => $rule->payout_type?->value,
                'fixed_amount' => $rule->fixed_amount !== null ? (float) $rule->fixed_amount : null,
                'slab_upgrade_mode' => $rule->slab_upgrade_mode?->value,
                'achievement_metric' => $rule->achievement_metric?->value,
                'retention_days' => $rule->retention_days,
                'effective_from' => $rule->effective_from?->toDateString(),
                'effective_to' => $rule->effective_to?->toDateString(),
            ],
            'slab' => $slab !== null ? [
                'id' => $slab->id,
                'min' => (float) $slab->achievement_min,
                'max' => $slab->achievement_max !== null ? (float) $slab->achievement_max : null,
                'amount' => (float) $slab->amount,
                'band' => $slab->bandLabel($rule),
            ] : null,
            'basis' => ['achievement' => $achievement, 'occurrence_count' => $occurrenceCount],
            'priced_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Phase 8.6 (D8.6-014): a pending calculation whose amount changes on recalculation is audited
     * with the old and new amount — a re-price is never silent.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function auditReprice(RecruiterIncentiveCalculation $calculation, array $attributes): void
    {
        if ((float) $calculation->amount === (float) $attributes['amount']) {
            return;
        }

        AuditLog::record($calculation, 'incentive_repriced', [
            'amount' => (float) $calculation->amount,
            'incentive_slab_id' => $calculation->incentive_slab_id,
        ], [
            'amount' => (float) $attributes['amount'],
            'incentive_slab_id' => $attributes['incentive_slab_id'],
        ]);
    }
}
