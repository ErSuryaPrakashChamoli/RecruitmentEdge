<?php

namespace App\Services\Automation\Actions\Handlers;

use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Models\AutomationExecution;
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

        $event = $this->timeline->record(
            $candidate,
            TimelineEventType::SystemEvent,
            (string) $config['title'],
            $config['description'] ?? null,
            TimelineSource::System,
            TimelineVisibility::Internal,
            related: ['application' => $context->application(), 'subject' => $context->subject],
            metadata: ['automation_rule_id' => $execution->automation_rule_id, 'automation_execution_id' => $execution->id],
        );

        return ActionOutcome::completed('Timeline note added', $event);
    }
}
