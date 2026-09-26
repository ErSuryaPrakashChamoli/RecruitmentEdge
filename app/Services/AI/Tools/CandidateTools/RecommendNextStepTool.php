<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Enums\ApplicationStatus;
use App\Models\CandidateApplication;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ProjectsForAi;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\NextBestAction\NextBestAction;
use App\Services\NextBestAction\NextBestActionService;

/**
 * RECOMMEND risk, advisory only: the next best action for one application (or a candidate's most
 * recent visible active application), taken from NextBestActionService — the same deterministic
 * rules the Action Center uses (Phase 6). Nothing is written — each recommendation names the
 * tool/screen the user would use to act on it, so any change still goes through the normal
 * confirmation-gated action tools.
 */
class RecommendNextStepTool implements AiTool
{
    use ProjectsForAi, ScopesToHierarchy;

    public function __construct(private readonly NextBestActionService $nextBestActions) {}

    public function name(): string
    {
        return 'recommend_next_step';
    }

    public function description(): string
    {
        return 'Recommend the next best action (advisory, nothing is changed) for a candidate application — or a candidate\'s latest active application — based on its stage, status, pending interviews/feedback, offers, joining, and overdue follow-ups.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'application_id' => ['type' => 'integer'],
                'candidate_id' => ['type' => 'integer', 'description' => 'Used when no application_id is given: picks the candidate\'s most recent visible active application'],
            ],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Recommend;
    }

    public function permission(): ?string
    {
        return 'candidates.viewAny';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $application = $this->resolveApplication($arguments, $user);

        if ($application === null) {
            return ToolResult::fail('Application not found, or not visible to you. Pass an application_id or a candidate_id.');
        }

        $signals = $this->nextBestActions->forApplication($application)
            ->map(fn (NextBestAction $action) => [
                'priority' => $action->legacyPriority(),
                'action' => $action->suggestedAction,
                'reason' => $action->reason,
                'suggested_tool' => $action->suggestedTool,
            ])
            ->all();
        $primary = $signals[0];

        return ToolResult::ok(
            data: [
                'application_id' => $application->id,
                'application_ref' => $application->application_code,
                'candidate_ref' => $candidateRef = $this->projector()->candidateRef($application->candidate),
                'stage' => $application->current_stage->label(),
                'status' => $application->status->label(),
                'days_since_last_activity' => $application->last_activity_at !== null ? (int) $application->last_activity_at->diffInDays(now()) : null,
                'recommended_action' => $primary,
                'other_signals' => array_slice($signals, 1),
                'advisory' => true,
            ],
            summary: "Next step for {$candidateRef}: {$primary['action']}",
            type: 'recommendation',
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveApplication(array $arguments, User $user): ?CandidateApplication
    {
        $query = $this->scopeRecruiterOwnedTo(CandidateApplication::query(), $user)
            ->with(NextBestActionService::RELATIONS);

        if (filled($arguments['application_id'] ?? null)) {
            return $query->find($arguments['application_id']);
        }

        if (blank($arguments['candidate_id'] ?? null)) {
            return null;
        }

        return $query
            ->where('candidate_id', $arguments['candidate_id'])
            ->orderByRaw('case when status = ? then 0 else 1 end', [ApplicationStatus::Active->value])
            ->latest('application_date')
            ->latest('id')
            ->first();
    }
}
