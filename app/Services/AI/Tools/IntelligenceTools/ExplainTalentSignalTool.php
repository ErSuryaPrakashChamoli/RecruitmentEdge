<?php

namespace App\Services\AI\Tools\IntelligenceTools;

use App\Enums\AiRiskLevel;
use App\Models\CandidateApplication;
use App\Models\IntelligenceEvidence;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\Intelligence\TalentSignalService;

/**
 * READ: the deterministic Talent Signal of one application — band, each component and the evidence
 * behind it. The model may explain it; it must not re-score or decide.
 */
class ExplainTalentSignalTool implements AiTool
{
    use ScopesToHierarchy;

    public function __construct(private readonly TalentSignalService $signals) {}

    public function name(): string
    {
        return 'explain_talent_signal';
    }

    public function description(): string
    {
        return 'Explain how a candidate application aligns with its requisition\'s Role DNA: the Talent Signal band, each component (skills, experience, education, location, history, interviews) and the evidence behind it. Deterministic rules — explain, never re-score, and never recommend rejection or selection.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['application_id' => ['type' => 'integer']], 'required' => ['application_id']];
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
        $application = $this->scopeRecruiterOwnedTo(CandidateApplication::query(), $user)->with(['candidate', 'requisition'])->find($arguments['application_id'] ?? null);

        if ($application === null) {
            return ToolResult::fail('Application not found, or not visible to you.');
        }

        $snapshot = $this->signals->refresh($application);

        return ToolResult::ok(
            data: [
                'application' => $application->application_code,
                'band' => $snapshot->band->label(),
                'rules_version' => $snapshot->rules_version,
                'role_dna_version' => $snapshot->roleDnaVersion?->version,
                'required_skill_coverage_percent' => $snapshot->required_coverage_pct,
                'data_completeness_percent' => $snapshot->completeness_pct,
                'components' => collect($snapshot->components['items'] ?? [])->map(fn (array $c) => ['name' => $c['label'], 'status' => $c['status'], 'summary' => $c['summary']])->values()->all(),
                'evidence' => $snapshot->evidence()->limit(25)->get()->map(fn (IntelligenceEvidence $e) => ['type' => $e->evidence_type->label(), 'about' => $e->subject_key, 'fact' => trim($e->label.': '.$e->value)])->all(),
                'computed_at' => $snapshot->computed_at->toIso8601String(),
            ],
            summary: "Talent Signal for {$application->application_code}: {$snapshot->band->label()}.",
            type: 'talent_signal',
        );
    }
}
