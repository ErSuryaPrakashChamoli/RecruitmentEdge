<?php

namespace App\Services\AI\Tools\ActionTools;

use App\Enums\AiRiskLevel;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;

/**
 * WRITE risk: reassigns candidate_applications.recruiter_id. There is no dedicated reassignment
 * service in the app today (the Filament form edits this field directly), so this tool does the
 * same — but only within the acting user's hierarchy, and only after human confirmation.
 */
class AssignCandidatesToRecruiterTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'assign_candidates_to_recruiter';
    }

    public function description(): string
    {
        return 'Reassign one or more candidate applications to a different recruiter. Requires human approval before executing.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'application_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1],
                'recruiter_employee_id' => ['type' => 'integer'],
            ],
            'required' => ['application_ids', 'recruiter_employee_id'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Write;
    }

    public function permission(): ?string
    {
        return 'candidates.reassign';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $visibleIds = $this->visibleEmployeeIds($user);
        $recruiter = Employee::query()->find($arguments['recruiter_employee_id'] ?? null);

        if ($recruiter === null || ($visibleIds !== null && ! $visibleIds->contains($recruiter->id))) {
            return ToolResult::fail('Recruiter not found, or not visible to you.');
        }

        $ids = array_slice(array_map('intval', $arguments['application_ids'] ?? []), 0, (int) config('ai.limits.max_bulk_action_size'));

        $applications = CandidateApplication::query()
            ->whereIn('id', $ids)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->get();

        if ($applications->isEmpty()) {
            return ToolResult::fail('None of the given applications were found or visible to you.');
        }

        $updated = 0;

        foreach ($applications as $application) {
            $application->forceFill(['recruiter_id' => $recruiter->id])->save();
            $updated++;
        }

        return ToolResult::ok(
            data: ['entity_type' => 'CandidateApplication', 'entity_ids' => $applications->pluck('id')->all(), 'assigned_to' => $recruiter->fullName()],
            summary: "Assigned {$updated} of ".count($ids)." candidate application(s) to {$recruiter->fullName()}.",
            type: 'action_result',
        );
    }
}
