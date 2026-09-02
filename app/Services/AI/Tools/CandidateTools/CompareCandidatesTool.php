<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;

class CompareCandidatesTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'compare_candidates';
    }

    public function description(): string
    {
        return 'Side-by-side comparison of 2-6 candidates on experience, skills, current stage, and expected salary. Does not judge fit — present the facts for the user/model to reason over.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'candidate_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2, 'maxItems' => 6],
            ],
            'required' => ['candidate_ids'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'candidates.viewAny';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $ids = array_slice(array_map('intval', $arguments['candidate_ids'] ?? []), 0, 6);
        $visibleIds = $this->visibleEmployeeIds($user);

        $candidates = Candidate::query()
            ->whereIn('id', $ids)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas(
                'applications',
                fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds),
            ))
            ->with(['applications' => fn ($q) => $q->latest('application_date')->limit(1)])
            ->get(['id', 'full_name', 'total_experience', 'relevant_experience', 'skills', 'current_company', 'current_designation', 'expected_salary', 'notice_period_days']);

        if ($candidates->count() < 2) {
            return ToolResult::fail('At least two visible candidates are required to compare.');
        }

        $rows = $candidates->map(fn (Candidate $c) => [
            'id' => $c->id,
            'name' => $c->full_name,
            'total_experience' => $c->total_experience,
            'relevant_experience' => $c->relevant_experience,
            'current_company' => $c->current_company,
            'current_designation' => $c->current_designation,
            'skills' => $c->skills,
            'expected_salary' => $c->expected_salary,
            'notice_period_days' => $c->notice_period_days,
            'current_stage' => optional($c->applications->first())->current_stage?->label(),
        ]);

        return ToolResult::ok(
            data: ['comparison' => $rows->toArray()],
            summary: "Compared {$rows->count()} candidates.",
            type: 'comparison_table',
        );
    }
}
