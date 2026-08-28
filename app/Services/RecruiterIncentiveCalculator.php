<?php

namespace App\Services;

use App\Enums\IncentiveCalculationStatus;
use App\Enums\IncentiveTriggerEvent;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentIncentiveSlab;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Section 31's incentive calculator. For a given trigger event, finds every active, in-scope
 * incentive rule, resolves the recruiter's achievement on that rule's configured metric (reusing
 * TargetResolutionService/RecruiterDailyMetricsService from the Performance engine — the same
 * "achievement %" concept, not a second implementation of it), picks the matching slab, and
 * writes a traceable RecruiterIncentiveCalculation.
 *
 * Duplicate-safe by construction: (rule, application, period) is unique at the DB level, and a
 * calculation already at Approved or later is never touched by a recalculation (Section 28).
 */
class RecruiterIncentiveCalculator
{
    public function __construct(
        private readonly TargetResolutionService $targets,
        private readonly RecruiterDailyMetricsService $metrics,
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

        $achievement = null;

        if ($rule->achievement_metric !== null) {
            $target = $this->targets->resolveForRange($recruiter, $rule->achievement_metric, $periodStart, $periodEnd);
            $actual = $this->metrics->actualFor($recruiter, $rule->achievement_metric, $periodStart, $periodEnd);
            $achievement = ($target !== null && $target > 0) ? round($actual / $target * 100, 2) : 0.0;
        }

        // A rule with no achievement_metric pays a flat amount per occurrence — the admin
        // configures a single catch-all slab (0 to unbounded) for that case.
        $slab = $rule->slabs->first(fn (RecruitmentIncentiveSlab $slab) => $slab->matches($achievement ?? 0.0));

        if ($slab === null) {
            return null;
        }

        $existing = RecruiterIncentiveCalculation::query()
            ->where('incentive_rule_id', $rule->id)
            ->where('candidate_application_id', $application->id)
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->first();

        if ($existing !== null && ! in_array($existing->status, [
            IncentiveCalculationStatus::Calculated,
            IncentiveCalculationStatus::PendingVerification,
        ], true)) {
            return $existing;
        }

        $retentionDueAt = $rule->retention_days !== null ? $eventDate->copy()->addDays($rule->retention_days) : null;
        $status = ($retentionDueAt !== null && $retentionDueAt->isFuture())
            ? IncentiveCalculationStatus::Calculated
            : IncentiveCalculationStatus::PendingVerification;

        $attributes = [
            'incentive_slab_id' => $slab->id,
            'employee_id' => $recruiter->id,
            'candidate_id' => $application->candidate_id,
            'achievement' => $achievement,
            'amount' => $slab->amount,
            'status' => $status,
            'retention_due_at' => $retentionDueAt,
            'calculated_at' => now(),
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return RecruiterIncentiveCalculation::query()->create([
            'incentive_rule_id' => $rule->id,
            'candidate_application_id' => $application->id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            ...$attributes,
        ]);
    }
}
