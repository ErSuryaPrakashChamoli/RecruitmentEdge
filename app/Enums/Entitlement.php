<?php

namespace App\Enums;

/**
 * SaaS-3: the entitlement registry — every capability a plan can grant, by stable key. Code asks
 * EntitlementService about these cases, never about a plan's name. A key a tenant's plan (and
 * overrides) does not define is denied: missing never means unlimited.
 *
 * Naming: features are `<module>.<capability>`; limits are `<resource>.<state>.max`. Adding a case
 * adds nothing to any plan — every plan version must grant it explicitly.
 */
enum Entitlement: string
{
    /** Every call to an AI provider made for the tenant (Copilot, summaries, indexing, AI insight). */
    case AiAssistant = 'ai.assistant';

    /** Automation rules: creating and activating them, and the engine running them. */
    case AutomationRules = 'automation.rules';

    /** Publishing postings to external job boards (the tenant's own careers site is always included). */
    case DistributionJobBoards = 'distribution.job_boards';

    /** Bulk data exports: table exports and report CSV downloads. */
    case ExportsData = 'exports.data';

    /** Requisitions that are not closed or cancelled. */
    case RequisitionsActiveMax = 'requisitions.active.max';

    /** Staff seats: members whose access to the tenant is Active. */
    case MembersActiveMax = 'members.active.max';

    public function type(): EntitlementType
    {
        return match ($this) {
            self::RequisitionsActiveMax, self::MembersActiveMax => EntitlementType::Limit,
            default => EntitlementType::Feature,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AiAssistant => 'AI assistant',
            self::AutomationRules => 'Automation',
            self::DistributionJobBoards => 'Job board distribution',
            self::ExportsData => 'Data exports',
            self::RequisitionsActiveMax => 'Active requisitions',
            self::MembersActiveMax => 'Staff seats',
        };
    }

    /**
     * What a person sees when the entitlement stops them (never plan or platform internals).
     */
    public function unavailableMessage(): string
    {
        return match ($this) {
            self::RequisitionsActiveMax => 'Your organisation has reached the number of active requisitions its plan allows. Close or cancel a requisition, or ask your administrator about upgrading the plan.',
            self::MembersActiveMax => 'Your organisation has used every staff seat its plan allows. Free a seat, or ask your administrator about upgrading the plan.',
            default => "{$this->label()} is not included in your organisation's plan. Ask your administrator about upgrading the plan.",
        };
    }
}
