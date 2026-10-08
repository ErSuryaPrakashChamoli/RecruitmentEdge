<?php

namespace App\Services\Automation\Actions\Handlers;

use App\Models\AuditLog;
use App\Models\AutomationExecution;
use App\Services\Automation\Actions\ActionOutcome;
use App\Services\Automation\Actions\Contracts\AutomationAction;
use App\Services\Automation\AutomationContext;

/**
 * Writes an entry to the audit log against the triggering record.
 */
class AddAuditEventAction implements AutomationAction
{
    public function key(): string
    {
        return 'add_audit_event';
    }

    public function label(): string
    {
        return 'Record an audit entry';
    }

    public function validate(array $config): array
    {
        return blank($config['note'] ?? null) ? ['An audit note is required.'] : [];
    }

    public function describe(array $config, ?AutomationContext $context = null): string
    {
        return 'Record audit entry "'.($config['note'] ?? '').'"';
    }

    public function execute(array $config, AutomationContext $context, AutomationExecution $execution, int $position): ActionOutcome
    {
        $entry = AuditLog::record($context->subject, 'automation_note', null, [
            'note' => (string) $config['note'],
            'automation_rule_id' => $execution->automation_rule_id,
            'automation_execution_id' => $execution->id,
        ]);

        return ActionOutcome::completed('Audit entry recorded', $entry);
    }
}
