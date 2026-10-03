<?php

namespace App\Services\AI\Actions;

use App\Enums\CandidateStage;
use App\Enums\FollowupType;
use App\Enums\InterviewMode;
use App\Models\RecruitmentRejectionReason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 8.10 (P810-AI-01): the decision parameters of a proposed AI action, listed on the approval
 * card from the stored arguments — the very arguments ActionExecutor runs — so the person approving
 * sees what they approve: the target stage, the rejection reason, remarks, the interview schedule,
 * the follow-up and the full message. Entity arguments (candidates, applications, interviewer,
 * assignee) are resolved to names by the Copilot page within the approver's hierarchy. An argument
 * this class does not know is still listed, never hidden. UI only: never sent to the model.
 */
class ApprovalParameterPreview
{
    /**
     * Arguments the Copilot page resolves to scoped names.
     *
     * @var array<int, string>
     */
    public const array ENTITY_ARGUMENTS = ['candidate_id', 'candidate_ids', 'application_id', 'application_ids', 'interviewer_employee_id', 'recruiter_employee_id'];

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, string>
     */
    public function lines(array $arguments): array
    {
        return collect($arguments)
            ->reject(fn (mixed $value, string $key): bool => in_array($key, self::ENTITY_ARGUMENTS, true) || $value === null || $value === '' || $value === [])
            ->map(fn (mixed $value, string $key): string => $this->line($key, is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            ->values()
            ->all();
    }

    private function line(string $key, string $value): string
    {
        return match ($key) {
            'stage' => 'Move to stage: '.(CandidateStage::tryFrom($value)?->label() ?? $value),
            'rejection_reason_id' => 'Rejection reason: '.(RecruitmentRejectionReason::query()->find((int) $value)?->name ?? "unknown reason ({$value})"),
            'remarks' => 'Remarks: '.$value,
            'subject' => 'Subject: '.$value,
            'body' => "Message:\n".$value,
            'scheduled_at' => 'Scheduled for: '.$this->dateTime($value),
            'mode' => 'Mode: '.(InterviewMode::tryFrom($value)?->label() ?? $value),
            'round_number' => 'Round: '.$value,
            'round_name' => 'Round name: '.$value,
            'location' => 'Location: '.$value,
            'meeting_link' => 'Meeting link: '.$value,
            'followup_type' => 'Follow-up type: '.(FollowupType::tryFrom($value)?->label() ?? $value),
            'followup_date' => 'Follow-up due: '.$this->dateTime($value),
            default => Str::headline($key).': '.$value,
        };
    }

    /**
     * As the tool will read it (Carbon::parse), with the value as given when it does not parse.
     */
    private function dateTime(string $value): string
    {
        try {
            return Carbon::parse($value)->format('D, d M Y · h:i A');
        } catch (Throwable) {
            return $value;
        }
    }
}
