<?php

namespace App\Services\AI\Tools\ActionTools;

use App\Enums\AiRiskLevel;
use App\Enums\FollowupType;
use App\Models\CandidateApplication;
use App\Models\RecruitmentFollowup;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class CreateFollowupTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'create_followup';
    }

    public function description(): string
    {
        return 'Schedule a follow-up (call, WhatsApp, email, interview/offer/joining/document confirmation) for a candidate application. Requires human approval before executing.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'application_id' => ['type' => 'integer'],
                'followup_type' => ['type' => 'string', 'description' => 'One of: '.implode(', ', array_map(fn ($c) => $c->value, FollowupType::cases()))],
                'followup_date' => ['type' => 'string', 'description' => 'ISO date/time'],
                'remarks' => ['type' => 'string'],
            ],
            'required' => ['application_id', 'followup_type', 'followup_date'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Write;
    }

    public function permission(): ?string
    {
        return 'followups.manage';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $type = FollowupType::tryFrom((string) ($arguments['followup_type'] ?? ''));

        if ($type === null) {
            return ToolResult::fail('Unknown follow-up type.');
        }

        $visibleIds = $this->visibleEmployeeIds($user);

        $application = CandidateApplication::query()
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->find($arguments['application_id'] ?? null);

        if ($application === null) {
            return ToolResult::fail('Application not found, or not visible to you.');
        }

        $followup = RecruitmentFollowup::query()->create([
            'candidate_application_id' => $application->id,
            'recruiter_id' => $application->recruiter_id,
            'followup_type' => $type,
            'followup_date' => $arguments['followup_date'],
            'status' => 'pending',
            'remarks' => $arguments['remarks'] ?? null,
            'created_by' => $user->employee_id,
        ]);

        return ToolResult::ok(
            data: ['entity_type' => 'RecruitmentFollowup', 'entity_ids' => [$followup->id]],
            summary: "Scheduled a {$type->label()} follow-up for ".Carbon::parse($arguments['followup_date'])->toDayDateTimeString().'.',
            type: 'action_result',
        );
    }
}
