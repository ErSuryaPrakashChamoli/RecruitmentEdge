<?php

namespace App\Services\AI\Tools\IntelligenceTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\Intelligence\HiringHealthService;

/**
 * READ: a requisition's Hiring Health — overall status and every metric with value, threshold and
 * status. Deterministic; the model explains it.
 */
class GetHiringHealthTool implements AiTool
{
    use ResolvesIntelligenceScope;

    public function __construct(private readonly HiringHealthService $health) {}

    public function name(): string
    {
        return 'get_hiring_health';
    }

    public function description(): string
    {
        return 'Get the Hiring Health of a requisition: overall status and each metric (days open, pipeline depth, sourcing velocity, interview velocity, SLA, offers, joining risk, stalled candidates, drop-off, source concentration, data completeness) with its value and threshold.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['requisition_id' => ['type' => 'integer']], 'required' => ['requisition_id']];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'intelligence.view';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $requisition = $this->visibleRequisition($user, $arguments['requisition_id'] ?? null);

        if ($requisition === null) {
            return ToolResult::fail('Requisition not found, or not visible to you.');
        }

        // Phase 8.5 (SEC-5): a refresh this read triggers is recorded with its actor and audited.
        $snapshot = $this->health->refresh($requisition, $user);

        return ToolResult::ok(
            data: [
                'requisition' => $requisition->code,
                'status' => $snapshot->status->label(),
                'computed_at' => $snapshot->computed_at->toIso8601String(),
                'rules_version' => $snapshot->rules_version,
                'metrics' => collect($snapshot->metrics)->map(fn (array $m) => ['metric' => $m['label'], 'value' => $m['display'], 'threshold' => $m['threshold'], 'status' => $m['status']])->all(),
            ],
            summary: "Hiring Health of {$requisition->code}: {$snapshot->status->label()}.",
            type: 'hiring_health',
        );
    }
}
