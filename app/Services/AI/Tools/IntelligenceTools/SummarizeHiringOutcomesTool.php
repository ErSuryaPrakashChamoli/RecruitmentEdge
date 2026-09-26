<?php

namespace App\Services\AI\Tools\IntelligenceTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\Outcomes\OutcomeAnalyticsService;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * READ (Phase 8.2): aggregate hiring outcomes in the user's scope — counts, rates with their
 * sample sizes, and what could not be observed. Deterministic aggregates only: no person, no
 * application or employee reference, no interviewer. The model explains; it never decides.
 */
class SummarizeHiringOutcomesTool implements AiTool
{
    use ResolvesIntelligenceScope;

    public function __construct(private readonly OutcomeAnalyticsService $analytics) {}

    public function name(): string
    {
        return 'summarize_hiring_outcomes';
    }

    public function description(): string
    {
        return 'Summarise recorded hiring outcomes (joined / no-show / dropout, offer decisions, time to hire, source to join, and 30/90/180-day status observations) for the requisitions visible to the user, optionally one requisition and a date range. Aggregates with sample sizes only. Describe them as observations, never as causes; a missing rate means too little history. Performance, attendance, probation and promotion are not recorded.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'requisition_id' => ['type' => 'integer'],
            'from' => ['type' => 'string', 'description' => 'Start date, YYYY-MM-DD (default: 365 days ago)'],
            'to' => ['type' => 'string', 'description' => 'End date, YYYY-MM-DD (default: today)'],
        ]];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'outcomes.view';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $filters = [];

        if (filled($arguments['requisition_id'] ?? null)) {
            $requisition = $this->visibleRequisition($user, $arguments['requisition_id']);

            if ($requisition === null) {
                return ToolResult::fail('Requisition not found, or not visible to you.');
            }

            $filters['requisition_id'] = $requisition->id;
        }

        foreach (['from', 'to'] as $key) {
            if (filled($arguments[$key] ?? null)) {
                try {
                    $filters[$key] = CarbonImmutable::parse((string) $arguments[$key])->toDateString();
                } catch (Throwable) {
                    return ToolResult::fail("'{$key}' must be a date (YYYY-MM-DD).");
                }
            }
        }

        $report = $this->analytics->report($user, $filters);
        $metrics = $report['metrics'];
        $basis = fn (array $metric) => ['sample_size' => $metric['sample_size'], 'history' => $metric['band']->label(), 'not_observed' => $metric['unknown']];

        return ToolResult::ok(
            data: [
                'requisition' => isset($requisition) ? $requisition->code : 'all visible requisitions',
                'period' => $report['period'],
                'joining' => ['counts' => $metrics['joining']['counts'], 'join_rate_pct' => $metrics['joining']['value'], ...$basis($metrics['joining'])],
                'offers' => ['counts' => $metrics['offers']['counts'], 'acceptance_rate_pct' => $metrics['offers']['value'], ...$basis($metrics['offers'])],
                'time_to_hire_days' => ['median' => $metrics['time_to_hire']['value'], 'average' => $metrics['time_to_hire']['band']->isSufficient() ? $metrics['time_to_hire']['average'] : null, ...$basis($metrics['time_to_hire'])],
                'source_to_join' => collect($metrics['source_to_join']['sources'])->map(fn (array $row) => ['source' => $row['source'], 'joined' => $row['joined'], 'no_show' => $row['no_show'], 'dropout' => $row['dropout'], 'join_rate_pct' => $row['rate'], 'sample_size' => $row['sample_size']])->all(),
                'status_observations' => collect($metrics['status_observations']['checkpoints'])->map(fn (array $row) => [
                    'checkpoint' => $row['label'], 'observed_active' => $row['active'], 'observed_inactive' => $row['inactive'], 'separated_before' => $row['separated'],
                    'not_observed' => $row['not_observed'], 'not_yet_due' => $row['not_yet_due'], 'active_of_observed_pct' => $row['active_rate'], 'sample_size' => $row['sample_size'],
                ])->all(),
                'rules' => 'Rates are withheld below '.config('outcomes.sample.insufficient_below', 3).' outcomes. Not observed is never a failure. Observed inactive is not confirmed as an exit.',
                'not_recorded' => array_values($report['unavailable']),
            ],
            summary: "Hiring outcomes {$report['period']['from']} to {$report['period']['to']}: {$metrics['joining']['counts']['joined']} joined, {$metrics['joining']['counts']['no_show']} no-show, {$metrics['joining']['counts']['dropout']} dropout.",
            type: 'hiring_outcomes',
        );
    }
}
