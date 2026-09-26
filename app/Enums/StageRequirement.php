<?php

namespace App\Enums;

/**
 * A precondition a configurable stage can demand before an application may enter it (Phase 4
 * "required fields/actions"). Checked by StageConfigurationService::unmetRequirements() and enforced
 * by StageTransitionService::moveToStage() — an authorised override may bypass them, and that
 * bypass is recorded on the stage history row.
 */
enum StageRequirement: string
{
    case Remarks = 'remarks';
    case Resume = 'resume';
    case Email = 'email';
    case InterviewScheduled = 'interview_scheduled';
    case InterviewFeedback = 'interview_feedback';
    case Offer = 'offer';
    case JoiningRecord = 'joining_record';

    public function label(): string
    {
        return match ($this) {
            self::Remarks => 'Remarks / reason',
            self::Resume => 'Resume on file',
            self::Email => 'Candidate email on file',
            self::InterviewScheduled => 'An interview scheduled',
            self::InterviewFeedback => 'Interview feedback submitted',
            self::Offer => 'An offer raised',
            self::JoiningRecord => 'A joining record',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $requirement) => [$requirement->value => $requirement->label()])->all();
    }
}
