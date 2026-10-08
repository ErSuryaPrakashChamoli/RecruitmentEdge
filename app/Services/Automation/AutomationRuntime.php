<?php

namespace App\Services\Automation;

use App\Models\AutomationExecution;

/**
 * Tracks the automation chain in progress in this process (Phase 6 loop prevention). While an
 * execution runs its actions, any domain event those actions dispatch is handled synchronously by
 * TriggerAutomationRules, which reads the current depth and rule chain from here — so a rule can
 * never re-enter itself and a chain can never grow past automation.max_chain_depth.
 */
class AutomationRuntime
{
    /**
     * @var array<int, array{execution_id: int, rule_id: int, depth: int}>
     */
    private array $stack = [];

    public function depth(): int
    {
        return $this->stack === [] ? 0 : end($this->stack)['depth'] + 1;
    }

    public function parentExecutionId(): ?int
    {
        return $this->stack === [] ? null : end($this->stack)['execution_id'];
    }

    /**
     * @return array<int, int>
     */
    public function ruleChain(): array
    {
        return array_column($this->stack, 'rule_id');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function within(AutomationExecution $execution, callable $callback): mixed
    {
        $this->stack[] = ['execution_id' => $execution->id, 'rule_id' => $execution->automation_rule_id, 'depth' => $execution->depth];

        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }
}
