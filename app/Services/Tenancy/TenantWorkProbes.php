<?php

namespace App\Services\Tenancy;

use App\Enums\AiToolCallStatus;
use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationRuleStatus;
use App\Enums\CommunicationStatus;
use App\Enums\EscalationStatus;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-7 (C5, S7 scheduler finding): which tenants could have work for a frequent tenant task.
 *
 * tenants:dispatch queued one job per usable tenant for every task — about 57 jobs per tenant per
 * hour, measured at ~34 ms of worker and database time each even when there is nothing to do
 * (docs/saas-7-discovery.md §7). For the tasks that run every 5 or 15 minutes, a probe names the
 * tenants that hold at least one row the task could act on; the rest are skipped.
 *
 * Each probe is a SUPERSET of the task's real work: it ignores the task's time thresholds (a
 * message queued a minute ago is included although the sweep waits ten) — a skipped tenant
 * provably has nothing the task would touch. One indexed DISTINCT tenant_id query per table,
 * across tenants: ids only, never rows (reviewed crossing, TenancyArchitectureTest).
 */
class TenantWorkProbes
{
    /**
     * Task => probe method. Tasks without a probe run for every usable tenant.
     *
     * @var array<string, string>
     */
    public const array PROBED = [
        'recruitment:automation:process' => 'automation',
        'recruitment:automation:dispatch' => 'activeAutomationRules',
        'ai:expire-pending-actions' => 'pendingAiActions',
        'reliability:sweep' => 'stuckWorkCandidates',
        'integrations:sweep' => 'integrations',
    ];

    /**
     * The tenants that may have work for $task, or null when $task is not probed (all tenants).
     *
     * @return list<int>|null
     */
    public function tenantsFor(string $task): ?array
    {
        $method = self::PROBED[$task] ?? null;

        if ($method === null) {
            return null;
        }

        return array_values(array_unique(array_map('intval', $this->{$method}())));
    }

    /**
     * AutomationEngine::processDue + EscalationService::processDue: active rules (owner authority
     * re-check), pending or running executions, pending escalations.
     *
     * @return list<int>
     */
    private function automation(): array
    {
        return [
            ...$this->activeAutomationRules(),
            ...DB::table('automation_executions')->whereIn('status', [AutomationExecutionStatus::Pending->value, AutomationExecutionStatus::Running->value])->distinct()->pluck('tenant_id')->all(),
            ...DB::table('automation_escalations')->where('status', EscalationStatus::Pending->value)->distinct()->pluck('tenant_id')->all(),
        ];
    }

    /**
     * DispatchAutomationRules: active rules (with a scheduled trigger — any active rule is a superset).
     *
     * @return list<int>
     */
    private function activeAutomationRules(): array
    {
        return DB::table('automation_rules')->where('status', AutomationRuleStatus::Active->value)->distinct()->pluck('tenant_id')->all();
    }

    /**
     * ActionExecutor::expirePending: pending AI actions awaiting confirmation.
     *
     * @return list<int>
     */
    private function pendingAiActions(): array
    {
        return DB::table('ai_tool_calls')->where('status', AiToolCallStatus::Pending->value)->distinct()->pluck('tenant_id')->all();
    }

    /**
     * SweepStuckWork: messages queued or sending, AI actions approved but not executed.
     *
     * @return list<int>
     */
    private function stuckWorkCandidates(): array
    {
        return [
            ...DB::table('candidate_communications')->whereIn('status', [CommunicationStatus::Queued->value, CommunicationStatus::Sending->value])->distinct()->pluck('tenant_id')->all(),
            ...DB::table('ai_tool_calls')->where('status', AiToolCallStatus::Approved->value)->distinct()->pluck('tenant_id')->all(),
        ];
    }

    /**
     * IntegrationsSweep: deliveries to retry, inbound events to process, records to prune — any
     * tenant holding a connection, an event, an inbound event or an idempotency key.
     *
     * @return list<int>
     */
    private function integrations(): array
    {
        return [
            ...DB::table('integration_connections')->distinct()->pluck('tenant_id')->all(),
            ...DB::table('webhook_events')->distinct()->pluck('tenant_id')->all(),
            ...DB::table('inbound_webhook_events')->distinct()->pluck('tenant_id')->all(),
            ...DB::table('api_idempotency_keys')->distinct()->pluck('tenant_id')->all(),
        ];
    }
}
