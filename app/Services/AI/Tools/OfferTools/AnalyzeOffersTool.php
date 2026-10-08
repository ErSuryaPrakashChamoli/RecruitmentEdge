<?php

namespace App\Services\AI\Tools\OfferTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ResolvesMetricPeriod;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricService;

class AnalyzeOffersTool implements AiTool
{
    use ResolvesMetricPeriod, ScopesToHierarchy;

    public function name(): string
    {
        return 'analyze_offers';
    }

    public function description(): string
    {
        return 'Breakdown of offers by status (released, accepted, rejected, expired, withdrawn) and acceptance rate for a date range (default: last 90 days).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'start_date' => ['type' => 'string'],
                'end_date' => ['type' => 'string'],
            ],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'offers.manage';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $period = $this->metricPeriod($arguments, 90);
        $query = MetricQuery::make($period, $user);
        $metrics = app(MetricService::class);

        // Phase 8.5 (D4): the governed acceptance rate — the dashboard's number — over offers first
        // released in the range; withdrawn offers are excluded (an employer action).
        $acceptance = $metrics->get('offer.acceptance_rate', $query);
        $decided = $metrics->get('offer.decided_acceptance_rate', $query);
        $counts = collect($acceptance->details)->only(['released', 'accepted', 'rejected', 'expired', 'withdrawn', 'awaiting']);

        return ToolResult::ok(
            data: [
                'offers_released' => (int) $counts->get('released', 0),
                'by_status' => $counts->except('released')->all(),
                'acceptance_rate_pct' => $acceptance->isAvailable() ? $acceptance->value : null,
                'accepted_vs_declined_pct' => $decided->isAvailable() ? $decided->value : null,
                'decided_offers' => $acceptance->sampleSize,
                'metric' => "{$acceptance->key} v{$acceptance->version}",
                'start_date' => $period->fromDate(),
                'end_date' => $period->toDate(),
            ],
            summary: $acceptance->isAvailable()
                ? "{$counts->get('released', 0)} offer(s) released in range; acceptance rate {$acceptance->value}% of {$acceptance->sampleSize} decided."
                : "{$counts->get('released', 0)} offer(s) released in range; acceptance rate not available ({$acceptance->status->label()}).",
            type: 'kpi_card',
        );
    }
}
