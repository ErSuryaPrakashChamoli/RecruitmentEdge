<?php

namespace App\Services\Automation\Actions\Contracts;

use App\Models\AutomationExecution;
use App\Services\Automation\Actions\ActionOutcome;
use App\Services\Automation\AutomationContext;

/**
 * One reusable automation action type (Phase 6). Handlers never write to the database directly for
 * domain changes — they call the existing engine that owns the change (CommunicationService,
 * NotificationDispatchService, RecruiterActionService, StageTransitionService,
 * CandidateTimelineService, AuditLog), which enforces its own rules.
 */
interface AutomationAction
{
    public function key(): string;

    public function label(): string;

    /**
     * Structural validation of the configuration; returns human-readable errors.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    public function validate(array $config): array;

    /**
     * What the action would do, for dry runs and the rule summary — no side effects.
     *
     * @param  array<string, mixed>  $config
     */
    public function describe(array $config, ?AutomationContext $context = null): string;

    /**
     * @param  array<string, mixed>  $config
     */
    public function execute(array $config, AutomationContext $context, AutomationExecution $execution, int $position): ActionOutcome;
}
