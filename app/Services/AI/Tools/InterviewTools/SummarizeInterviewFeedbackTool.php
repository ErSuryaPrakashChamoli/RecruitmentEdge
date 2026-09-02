<?php

namespace App\Services\AI\Tools\InterviewTools;

use App\Enums\AiRiskLevel;
use App\Models\CandidateApplication;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gathers structured feedback facts across every interview round for an application — the model
 * summarizes/compares, this tool never invents a recommendation on its own (spec section 45).
 */
class SummarizeInterviewFeedbackTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'summarize_interview_feedback';
    }

    public function description(): string
    {
        return 'Gather all interview round scores/recommendations/feedback text for a candidate application, in round order.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['application_id' => ['type' => 'integer']],
            'required' => ['application_id'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'interviews.manage';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $visibleIds = $this->visibleEmployeeIds($user);

        $application = CandidateApplication::query()
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->with(['candidate:id,full_name', 'interviews.feedback.interviewer:id,first_name,last_name'])
            ->find($arguments['application_id'] ?? null);

        if ($application === null) {
            return ToolResult::fail('Application not found, or not visible to you.');
        }

        $rounds = $application->interviews->sortBy('round_number')->map(fn ($interview) => [
            'round_number' => $interview->round_number,
            'round_name' => $interview->round_name,
            'status' => $interview->status->label(),
            'result' => $interview->result?->label(),
            'feedback' => $interview->feedback->map(fn ($f) => [
                'interviewer' => $f->interviewer?->fullName(),
                'score' => $f->score,
                'recommendation' => $f->recommendation?->label(),
                'feedback' => $f->feedback,
            ]),
        ]);

        return ToolResult::ok(
            data: ['candidate' => $application->candidate?->full_name, 'rounds' => $rounds->values()->toArray()],
            summary: 'Gathered feedback for '.$rounds->count().' interview round(s).',
            type: 'timeline',
        );
    }
}
