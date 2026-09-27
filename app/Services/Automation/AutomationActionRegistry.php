<?php

namespace App\Services\Automation;

use App\Services\Automation\Actions\Contracts\AutomationAction;
use App\Services\Automation\Actions\Handlers\AddAuditEventAction;
use App\Services\Automation\Actions\Handlers\AddTimelineEventAction;
use App\Services\Automation\Actions\Handlers\CreateFollowupAction;
use App\Services\Automation\Actions\Handlers\CreateRecruiterActionAction;
use App\Services\Automation\Actions\Handlers\EscalateAction;
use App\Services\Automation\Actions\Handlers\HoldApplicationAction;
use App\Services\Automation\Actions\Handlers\MoveStageAction;
use App\Services\Automation\Actions\Handlers\NotifyAction;
use App\Services\Automation\Actions\Handlers\SendCommunicationAction;
use Illuminate\Support\Collection;

/**
 * The fixed set of action types a rule may use (Phase 6). Adding a type means adding a handler
 * here — rule configuration can never name a class, a table or code.
 */
class AutomationActionRegistry
{
    /**
     * @var array<int, class-string<AutomationAction>>
     */
    public const array HANDLERS = [
        SendCommunicationAction::class,
        NotifyAction::class,
        CreateRecruiterActionAction::class,
        CreateFollowupAction::class,
        EscalateAction::class,
        MoveStageAction::class,
        HoldApplicationAction::class,
        AddTimelineEventAction::class,
        AddAuditEventAction::class,
    ];

    /**
     * Phase 8.7 (D8.7-010 b): the permission a rule owner must still hold, when the run happens, for
     * the automation to take each action on their authority. Actions not listed only notify staff
     * or record notes about the run itself.
     *
     * @var array<string, string>
     */
    public const array PERMISSIONS = [
        'send_communication' => 'communications.send',
        'move_stage' => 'pipeline.transition',
        'hold_application' => 'pipeline.transition',
        'create_action' => 'actions.manage',
        'create_followup' => 'followups.manage',
        'add_timeline_event' => 'candidates.update',
    ];

    /**
     * @var Collection<string, AutomationAction>|null
     */
    private ?Collection $handlers = null;

    /**
     * @return Collection<string, AutomationAction>
     */
    public function all(): Collection
    {
        return $this->handlers ??= collect(self::HANDLERS)
            ->map(fn (string $class) => app($class))
            ->keyBy(fn (AutomationAction $handler) => $handler->key());
    }

    public function permissionFor(string $key): ?string
    {
        return self::PERMISSIONS[$key] ?? null;
    }

    public function find(string $key): ?AutomationAction
    {
        return $this->all()->get($key);
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->all()->map(fn (AutomationAction $handler) => $handler->label())->all();
    }
}
