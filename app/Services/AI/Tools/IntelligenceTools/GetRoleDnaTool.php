<?php

namespace App\Services\AI\Tools\IntelligenceTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\Intelligence\RoleDnaService;

/**
 * READ: a requisition's current Role DNA — attributes with their origin (configured, inferred,
 * historical, human-confirmed) and any pending, unconfirmed AI suggestions shown separately.
 */
class GetRoleDnaTool implements AiTool
{
    use ResolvesIntelligenceScope;

    public function __construct(private readonly RoleDnaService $roleDna) {}

    public function name(): string
    {
        return 'get_role_dna';
    }

    public function description(): string
    {
        return 'Get the Role DNA of a requisition: required/preferred skills, experience, education, location, interview dimensions and historical hiring patterns, each with where it came from. Unconfirmed AI suggestions are listed separately and must not be treated as requirements.';
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

        $version = $this->roleDna->currentVersionFor($requisition, $user);
        $describe = fn (array $a) => ['category' => $a['category'], 'name' => $a['label'], 'value' => $a['value'], 'level' => $a['level'], 'origin' => $a['origin'], 'note' => $a['note'] ?? null];

        return ToolResult::ok(
            data: [
                'requisition' => $requisition->code,
                'version' => $version->version,
                'status' => $this->roleDna->profileFor($requisition)->status->value,
                'attributes' => $version->effectiveAttributes()->map($describe)->values()->all(),
                'unconfirmed_ai_suggestions' => $version->pendingSuggestions()->map($describe)->values()->all(),
            ],
            summary: "Role DNA v{$version->version} for {$requisition->code}.",
            type: 'role_dna',
        );
    }
}
