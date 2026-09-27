<?php

namespace App\Services\Automation\Actions\Handlers;

use App\Enums\FollowupStatus;
use App\Enums\FollowupType;
use App\Models\AutomationExecution;
use App\Models\RecruitmentFollowup;
use App\Services\Automation\Actions\ActionOutcome;
use App\Services\Automation\Actions\Contracts\AutomationAction;
use App\Services\Automation\AutomationContext;

/**
 * Schedules a follow-up (reminder) in the existing follow-up log for the application's recruiter —
 * the same record type recruiters create by hand, so it appears in the follow-up calendar,
 * performance metrics and overdue alerts with no extra wiring.
 */
class CreateFollowupAction implements AutomationAction
{
    public function key(): string
    {
        return 'create_followup';
    }

    public function label(): string
    {
        return 'Schedule a follow-up reminder';
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (FollowupType::tryFrom((string) ($config['followup_type'] ?? '')) === null) {
            $errors[] = 'Choose the follow-up type.';
        }

        if (! is_numeric($config['due_in_hours'] ?? null) || (int) $config['due_in_hours'] < 0 || (int) $config['due_in_hours'] > 24 * 60) {
            $errors[] = 'The follow-up must be due between 0 hours and 60 days from now.';
        }

        return $errors;
    }

    public function describe(array $config, ?AutomationContext $context = null): string
    {
        $type = FollowupType::tryFrom((string) ($config['followup_type'] ?? ''))?->label() ?? '?';

        return "Schedule a {$type} follow-up for the recruiter in ".($config['due_in_hours'] ?? '?').' hour(s)';
    }

    public function execute(array $config, AutomationContext $context, AutomationExecution $execution, int $position): ActionOutcome
    {
        $application = $context->application();

        if ($application === null || $application->recruiter_id === null) {
            return ActionOutcome::skipped('No application with a recruiter to follow up.');
        }

        // Phase 8.7 (DQ-87-11): the run reference makes a re-run of the same action (after a worker
        // died mid-run) find the follow-up it already created instead of adding a second one.
        $marker = "[Automation: {$execution->rule?->name}, run {$execution->id}.{$position}]";

        if ($existing = RecruitmentFollowup::query()->where('candidate_application_id', $application->id)->where('remarks', 'like', '%'.$marker)->first()) {
            return ActionOutcome::completed("Follow-up already scheduled for {$existing->followup_date->toDayDateTimeString()}", $existing);
        }

        $followup = RecruitmentFollowup::query()->create([
            'candidate_application_id' => $application->id,
            'recruiter_id' => $application->recruiter_id,
            'followup_type' => FollowupType::from((string) $config['followup_type']),
            'followup_date' => now()->addHours((int) $config['due_in_hours']),
            'status' => FollowupStatus::Pending,
            'remarks' => trim(($config['remarks'] ?? '').' '.$marker),
        ]);

        return ActionOutcome::completed("Follow-up scheduled for {$followup->followup_date->toDayDateTimeString()}", $followup);
    }
}
