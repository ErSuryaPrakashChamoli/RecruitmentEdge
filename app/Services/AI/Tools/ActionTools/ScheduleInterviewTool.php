<?php

namespace App\Services\AI\Tools\ActionTools;

use App\Enums\AiRiskLevel;
use App\Enums\InterviewMode;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use App\Services\AI\Calendar\Contracts\CalendarProviderInterface;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\InterviewService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * EXTERNAL risk: scheduling an interview commits interviewer time and (once a real
 * CalendarProviderInterface implementation exists) may send external calendar invites. Creation
 * goes through InterviewService::schedule() — the same path as every Filament surface, so the
 * stage sync and notifications match — then best-effort syncs to CalendarProviderInterface, which
 * is a no-op NullCalendarProvider until a real calendar credential is configured.
 */
class ScheduleInterviewTool implements AiTool
{
    use ScopesToHierarchy;

    public function __construct(
        private readonly CalendarProviderInterface $calendar,
        private readonly InterviewService $interviews,
    ) {}

    public function name(): string
    {
        return 'schedule_interview';
    }

    public function description(): string
    {
        return 'Schedule an interview round for a candidate application. Requires human approval before executing.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'application_id' => ['type' => 'integer'],
                'round_number' => ['type' => 'integer', 'description' => 'Optional; defaults to the next round for this application'],
                'round_name' => ['type' => 'string'],
                'interviewer_employee_id' => ['type' => 'integer'],
                'scheduled_at' => ['type' => 'string', 'description' => 'ISO date/time'],
                'mode' => ['type' => 'string', 'description' => 'One of: in_person, phone, video_call'],
                'location' => ['type' => 'string'],
                'meeting_link' => ['type' => 'string'],
            ],
            'required' => ['application_id', 'interviewer_employee_id', 'scheduled_at', 'mode'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::External;
    }

    public function permission(): ?string
    {
        return 'interviews.manage';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $mode = InterviewMode::tryFrom((string) ($arguments['mode'] ?? ''));

        if ($mode === null) {
            return ToolResult::fail('Unknown interview mode.');
        }

        $visibleIds = $this->visibleEmployeeIds($user);

        $application = CandidateApplication::query()
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->with('candidate')
            ->find($arguments['application_id'] ?? null);

        if ($application === null) {
            return ToolResult::fail('Application not found, or not visible to you.');
        }

        $interviewer = Employee::query()->find($arguments['interviewer_employee_id'] ?? null);

        if ($interviewer === null) {
            return ToolResult::fail('Interviewer not found.');
        }

        try {
            $scheduledAt = Carbon::parse($arguments['scheduled_at'] ?? '');
        } catch (Throwable) {
            return ToolResult::fail('Invalid scheduled_at date/time.');
        }

        try {
            $interview = $this->interviews->schedule($application, [
                'round_number' => $arguments['round_number'] ?? null,
                'round_name' => $arguments['round_name'] ?? null,
                'interviewer_id' => $interviewer->id,
                'scheduled_at' => $scheduledAt,
                'mode' => $mode,
                'location' => $arguments['location'] ?? null,
                'meeting_link' => $arguments['meeting_link'] ?? null,
            ], $user->employee);
        } catch (DomainException $e) {
            return ToolResult::fail($e->getMessage());
        }

        $roundLabel = $interview->round_name ?? "Round {$interview->round_number}";

        $this->calendar->createEvent(
            title: "Interview: {$application->candidate?->full_name} - {$roundLabel}",
            start: $scheduledAt,
            end: $scheduledAt->copy()->addHour(),
            attendeeEmails: array_filter([$interviewer->email]),
        );

        return ToolResult::ok(
            data: ['entity_type' => 'Interview', 'entity_ids' => [$interview->id]],
            summary: "Scheduled {$roundLabel} for {$application->candidate?->full_name} on {$scheduledAt->toDayDateTimeString()}.",
            type: 'action_result',
        );
    }
}
