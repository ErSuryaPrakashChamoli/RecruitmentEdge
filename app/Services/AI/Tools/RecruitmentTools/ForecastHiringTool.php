<?php

namespace App\Services\AI\Tools\RecruitmentTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\RecruitmentAnalyticsService;
use Carbon\CarbonImmutable;

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

        $end = CarbonImmutable::now();
        $start = $end->subDays($lookbackDays);

        $funnel = $this->analytics->funnel($start, $end, $user);
        $joinedRow = $funnel->firstWhere('stage.value', 'joined') ?? $funnel->last();
        $conversionPct = $joinedRow['conversion_from_sourced'] ?? null;

        if ($conversionPct === null || $conversionPct <= 0) {
            return ToolResult::ok(
                data: ['target_hires' => $targetHires, 'conversion_rate_pct' => $conversionPct],
                summary: 'Not enough historical Sourced-to-Joined data in the lookback window to forecast a required sourcing volume.',
                type: 'kpi_card',
            );
        }

        $requiredSourced = (int) ceil($targetHires / ($conversionPct / 100));

        return ToolResult::ok(
            data: [
                'target_hires' => $targetHires,
                'historical_conversion_rate_pct' => $conversionPct,
                'lookback_days' => $lookbackDays,
                'required_sourced_candidates' => $requiredSourced,
            ],
            summary: "Based on a {$conversionPct}% historical Sourced-to-Joined rate, you'd need to source roughly {$requiredSourced} candidates to reach {$targetHires} hires.",
            type: 'kpi_card',
        );
    }
}
