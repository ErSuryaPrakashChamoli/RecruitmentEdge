<?php

namespace App\Console\Commands;

use App\Enums\CommunicationTrigger;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Models\CandidateJoining;
use App\Models\Interview;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\MessageContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled candidate reminders (Phase 5E): interviews in the next reminder window and joinings a
 * configured number of days away. Idempotency keys include the interview time / joining date, so
 * hourly runs never repeat a reminder, while a rescheduled interview gets a fresh one.
 */
#[Signature('communications:send-reminders')]
#[Description('Send candidate interview and joining reminders using the active reminder templates')]
class SendCandidateReminders extends Command
{
    /**
     * Template keys this command already sends; reserved from automation rules for the same reason
     * as SendCandidateCommunications::TEMPLATE_KEYS.
     *
     * @var array<int, string>
     */
    public const array TEMPLATE_KEYS = ['interview_reminder', 'joining_reminder'];

    public function handle(CommunicationService $communications): int
    {
        $sent = 0;

        Interview::query()
            ->whereIn('status', [...InterviewStatus::unconfirmed(), InterviewStatus::Confirmed])
            ->whereBetween('scheduled_at', [now(), now()->addHours((int) config('communications.interview_reminder_hours', 24))])
            ->with('candidateApplication.candidate', 'candidateApplication.requisition.designation', 'candidateApplication.recruiter')
            ->chunkById(200, function ($interviews) use ($communications, &$sent): void {
                foreach ($interviews as $interview) {
                    $sent += count($communications->sendAutomatic('interview_reminder', MessageContext::forInterview($interview), "interview.reminder:{$interview->id}:{$interview->scheduled_at->timestamp}", trigger: CommunicationTrigger::Reminder));
                }
            });

        CandidateJoining::query()
            ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
            ->whereDate('expected_doj', today()->addDays((int) config('communications.joining_reminder_days', 2)))
            ->with('candidateApplication.candidate', 'candidateApplication.requisition.designation', 'candidateApplication.recruiter')
            ->chunkById(200, function ($joinings) use ($communications, &$sent): void {
                foreach ($joinings as $joining) {
                    $application = $joining->candidateApplication;
                    $sent += count($communications->sendAutomatic('joining_reminder', new MessageContext($application->candidate, $application, joining: $joining), "joining.reminder:{$joining->id}:{$joining->expected_doj->toDateString()}", trigger: CommunicationTrigger::Reminder));
                }
            });

        $this->info("Queued {$sent} candidate reminder(s).");

        return self::SUCCESS;
    }
}
