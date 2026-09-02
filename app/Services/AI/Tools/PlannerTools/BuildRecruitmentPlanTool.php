<?php

namespace App\Services\AI\Tools\PlannerTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\RecruitmentAnalyticsService;
use Carbon\CarbonImmutable;

/**
 * "We need 50 sales executives in Delhi within 45 days" — deterministic sourcing math (never asks
 * the model to guess a conversion rate), plus optional external market context. This is READ/
 * RECOMMEND only: it proposes milestones, it never creates requisitions/tasks itself — that would
 * be a separate WRITE action requiring confirmation (spec section 35).
 */
class BuildRecruitmentPlanTool implements AiTool
{
    public function __construct(
        private readonly RecruitmentAnalyticsService $analytics,
        private readonly AiGateway $gateway,
    ) {}

    public function name(): string
    {
        return 'build_recruitment_plan';
    }

    public function description(): string
    {
        return 'Build a hiring plan for a target number of hires within a deadline: required sourcing volume (from historical conversion), weekly milestones, and risks. Optionally includes external market context.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_hires' => ['type' => 'integer'],
                'days' => ['type' => 'integer', 'description' => 'Deadline in days from today'],
                'role' => ['type' => 'string'],
                'location' => ['type' => 'string'],
            ],
            'required' => ['target_hires', 'days'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Recommend;
    }

    public function permission(): ?string
    {
        return 'requisitions.create';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $targetHires = max(1, (int) $arguments['target_hires']);
        $days = max(1, (int) $arguments['days']);

        $end = CarbonImmutable::now();
        $start = $end->subDays(90);
        $funnel = $this->analytics->funnel($start, $end, $user);
        $joinedRow = $funnel->firstWhere('stage.value', 'joined') ?? $funnel->last();
        $conversionPct = $joinedRow['conversion_from_sourced'] ?? null;

        $requiredSourced = ($conversionPct !== null && $conversionPct > 0)
            ? (int) ceil($targetHires / ($conversionPct / 100))
            : null;

        $weeks = max(1, (int) ceil($days / 7));
        $weeklySourcingTarget = $requiredSourced !== null ? (int) ceil($requiredSourced / $weeks) : null;
        $weeklyHireTarget = (int) ceil($targetHires / $weeks);

        $risks = [];

        if ($conversionPct === null) {
            $risks[] = 'No recent historical conversion data — sourcing volume could not be estimated and should be set conservatively.';
        }
        if ($days < 14 && $targetHires > 10) {
            $risks[] = 'Tight timeline relative to hire volume — consider adding recruiters or sourcing channels.';
        }

        $marketContext = [];

        if (config('ai.features.web_search_enabled') && filled($arguments['role'] ?? null)) {
            $query = trim(collect([$arguments['role'], $arguments['location'] ?? null, 'hiring market', (string) $end->year])->filter()->implode(' '));
            $marketContext = array_map(fn ($r) => $r->toArray(), $this->gateway->research($query, $user));
        }

        return ToolResult::ok(
            data: [
                'target_hires' => $targetHires,
                'deadline_days' => $days,
                'historical_conversion_rate_pct' => $conversionPct,
                'required_sourced_candidates' => $requiredSourced,
                'weekly_sourcing_target' => $weeklySourcingTarget,
                'weekly_hire_target' => $weeklyHireTarget,
                'weeks' => $weeks,
                'risks' => $risks,
                'external_market_context' => $marketContext,
            ],
            summary: $requiredSourced !== null
                ? "Plan: source ~{$requiredSourced} candidates (~{$weeklySourcingTarget}/week) over {$weeks} weeks to reach {$targetHires} hires in {$days} days."
                : "Target: {$targetHires} hires in {$days} days — not enough historical data to size the sourcing funnel confidently.",
            type: 'plan',
        );
    }
}
