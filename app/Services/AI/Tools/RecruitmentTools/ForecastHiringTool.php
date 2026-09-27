<?php

namespace App\Services\AI\Tools\RecruitmentTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\Metrics\MetricPeriod;
use App\Services\RecruitmentAnalyticsService;

/**
 * Deterministic sourcing-volume math from historical Sourced->Joined conversion — never asks the
 * model to guess a conversion rate (spec section 48: the LLM is not a forecasting engine).
 */
class ForecastHiringTool implements AiTool
{
    public function __construct(private readonly RecruitmentAnalyticsService $analytics) {}

    public function name(): string
    {
        return 'forecast_hiring';
    }

    public function description(): string
    {
        return 'Given a target number of hires, estimate how many candidates need to be sourced, based on the historical Sourced-to-Joined conversion rate over a trailing lookback window (default 90 days).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_hires' => ['type' => 'integer'],
                'lookback_days' => ['type' => 'integer', 'description' => 'Historical window to compute conversion from, default 90'],
            ],
            'required' => ['target_hires'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Recommend;
    }

    public function permission(): ?string
    {
        return 'performance.view';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $targetHires = max(1, (int) ($arguments['target_hires'] ?? 0));
        $lookbackDays = max(30, (int) ($arguments['lookback_days'] ?? 90));

        // Phase 8.5: the cohort funnel's application -> joined rate (pipeline.funnel). DF-13: the
        // same keys whether or not there is enough history.
        $period = MetricPeriod::lastDays($lookbackDays);
        $funnel = $this->analytics->metric('pipeline.funnel', $period->from, $period->lastInstant(), $user);
        $conversionPct = $funnel->isAvailable() && $funnel->value > 0 ? $funnel->value : null;
        $requiredSourced = $conversionPct !== null ? (int) ceil($targetHires / ($conversionPct / 100)) : null;

        return ToolResult::ok(
            data: [
                'target_hires' => $targetHires,
                'historical_conversion_rate_pct' => $conversionPct,
                'lookback_days' => $lookbackDays,
                'applications_in_lookback' => $funnel->sampleSize,
                'required_sourced_candidates' => $requiredSourced,
                'basis' => 'Share of applications created in the lookback window that have joined so far (recent applications may still join).',
            ],
            summary: $requiredSourced !== null
                ? "Based on a {$conversionPct}% historical application-to-joined rate, you'd need roughly {$requiredSourced} applications to reach {$targetHires} hires."
                : 'Not enough historical application-to-joined data in the lookback window to forecast a required sourcing volume.',
            type: 'kpi_card',
        );
    }
}
