<?php

namespace App\Services\AI\Tools\OfferTools;

use App\Enums\AiRiskLevel;
use App\Models\Offer;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ResolvesMetricPeriod;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricService;

class AnalyzeJoiningConversionTool implements AiTool
{
    use ResolvesMetricPeriod, ScopesToHierarchy;

    public function name(): string
    {
        return 'analyze_joining_conversion';
    }

    public function description(): string
    {
        return 'Offer-accepted-to-actually-joined conversion rate for a date range (default: last 90 days) — how many accepted offers turn into real joins vs no-shows/dropouts.';
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
        return 'joining.confirm';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $period = $this->metricPeriod($arguments, 90);

        // Phase 8.5 (DF-4): joining.offer_to_join — linked by application, never by offer id, so a
        // re-linked or deleted offer can never turn a hire into "not joined".
        $result = app(MetricService::class)->get('joining.offer_to_join', MetricQuery::make($period, $user));

        return ToolResult::ok(
            data: [
                'accepted_offers' => (int) $result->detail('accepted_applications', 0),
                'actually_joined' => (int) $result->detail('joined', 0),
                'did_not_join' => (int) $result->detail('not_joined', 0),
                'awaiting_joining' => (int) $result->detail('awaiting', 0),
                'cancelled' => (int) $result->detail('cancelled', 0),
                'conversion_rate_pct' => $result->isAvailable() ? $result->value : null,
                'metric' => "{$result->key} v{$result->version}",
                'start_date' => $period->fromDate(),
                'end_date' => $period->toDate(),
            ],
            summary: $result->isAvailable()
                ? "{$result->value}% of accepted offers whose joining is decided converted to actual joins ({$result->detail('joined', 0)} of {$result->sampleSize}); {$result->detail('awaiting', 0)} still awaiting joining."
                : 'Not enough decided joinings among offers accepted in this range for a conversion rate.',
            type: 'kpi_card',
        );
    }
}
