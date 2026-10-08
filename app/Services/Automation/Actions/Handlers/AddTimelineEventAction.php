<?php

namespace App\Services\Automation\Actions\Handlers;

use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Models\AutomationExecution;
use App\Models\CandidateTimelineEvent;
use App\Services\Automation\Actions\ActionOutcome;
use App\Services\Automation\Actions\Contracts\AutomationAction;
use App\Services\Automation\AutomationContext;
use App\Services\CandidateTimelineService;

/**
 * Adds an internal (never candidate-visible) entry to the candidate timeline.
 */
class AddTimelineEventAction implements AutomationAction
{
    public function __construct(private readonly CandidateTimelineService $timeline) {}

    public function key(): string
    {
        return 'add_timeline_event';
    }

    public function label(): string
    {
        return 'Add an internal timeline note';
    }

    public function validate(array $config): array
    {
        return blank($config['title'] ?? null) ? ['A timeline title is required.'] : [];
    }

    public function describe(array $config, ?AutomationContext $context = null): string
    {
        return 'Add internal timeline note "'.($config['title'] ?? '').'"';
    }

    public function execute(array $config, AutomationContext $context, AutomationExecution $execution, int $position): ActionOutcome
    {
        $candidate = $context->candidate();

        if ($candidate === null) {
            return ActionOutcome::skipped('No candidate timeline for this record.');
        }

        // Phase 8.7 (DQ-87-11): a re-run of the same action finds the note it already added.
        $existing = CandidateTimelineEvent::query()
            ->where('candidate_id', $candidate->id)
            ->where('metadata->automation_execution_id', $execution->id)
            ->where('metadata->automation_position', $position)
            ->first();

        if ($existing !== null) {
            return ActionOutcome::completed('Timeline note already added', $existing);
        }

        $event = $this->timeline->record(
            $candidate,
            TimelineEventType::SystemEvent,
            (string) $config['title'],
            $config['description'] ?? null,
            TimelineSource::System,
            TimelineVisibility::Internal,
            related: ['application' => $context->application(), 'subject' => $context->subject],
            metadata: ['automation_rule_id' => $execution->automation_rule_id, 'automation_execution_id' => $execution->id, 'automation_position' => $position],
        );

        return ActionOutcome::completed('Timeline note added', $event);
    }
}
