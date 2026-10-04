<?php

namespace App\Services\Platform\Commercial;

use App\Enums\Entitlement;
use App\Enums\PlanStatus;

/**
 * SaaS-3: the platform's plan catalog as code — stable plan codes, and what each published version
 * grants for every registry key. PlanCatalogService::sync() publishes a version that does not
 * exist yet and never edits one that does: to change a plan, add its next version here.
 *
 * The starter / growth / enterprise values are development and test defaults, not an offer: the
 * catalog sold to customers is an owner decision (docs/saas-3-decision-register.md, D-S3-O1).
 * There are no prices here; pricing is SaaS-4.
 *
 * Values: true / false for a feature; an integer or self::UNLIMITED for a limit.
 */
final class PlanCatalog
{
    public const string UNLIMITED = 'unlimited';

    /**
     * @return array<string, array{name: string, description: string, status: PlanStatus, versions: array<int, array<string, bool|int|string>>}>
     */
    public static function definitions(): array
    {
        return [
            'legacy' => [
                'name' => 'Legacy (all features, no limits)',
                'description' => 'Internal: every capability that existed before plans, without limits. Not offered to new customers.',
                'status' => PlanStatus::Internal,
                'versions' => [1 => self::everything()],
            ],
            'starter' => [
                'name' => 'Starter',
                'description' => 'Core hiring for a small team.',
                'status' => PlanStatus::Active,
                'versions' => [1 => [
                    Entitlement::AiAssistant->value => false,
                    Entitlement::AutomationRules->value => false,
                    Entitlement::DistributionJobBoards->value => true,
                    Entitlement::ExportsData->value => true,
                    Entitlement::RequisitionsActiveMax->value => 10,
                    Entitlement::MembersActiveMax->value => 5,
                ]],
            ],
            'growth' => [
                'name' => 'Growth',
                'description' => 'Hiring at pace, with AI and automation.',
                'status' => PlanStatus::Active,
                'versions' => [1 => [
                    Entitlement::AiAssistant->value => true,
                    Entitlement::AutomationRules->value => true,
                    Entitlement::DistributionJobBoards->value => true,
                    Entitlement::ExportsData->value => true,
                    Entitlement::RequisitionsActiveMax->value => 50,
                    Entitlement::MembersActiveMax->value => 25,
                ]],
            ],
            'enterprise' => [
                'name' => 'Enterprise',
                'description' => 'Everything, without limits.',
                'status' => PlanStatus::Active,
                'versions' => [1 => self::everything()],
            ],
        ];
    }

    /**
     * @return array<string, bool|string>
     */
    private static function everything(): array
    {
        return [
            Entitlement::AiAssistant->value => true,
            Entitlement::AutomationRules->value => true,
            Entitlement::DistributionJobBoards->value => true,
            Entitlement::ExportsData->value => true,
            Entitlement::RequisitionsActiveMax->value => self::UNLIMITED,
            Entitlement::MembersActiveMax->value => self::UNLIMITED,
        ];
    }

    /**
     * The stored form of a catalog value.
     *
     * @return array{enabled: bool, limit_value: int|null, is_unlimited: bool}
     */
    public static function stored(bool|int|string $value): array
    {
        return match (true) {
            $value === self::UNLIMITED => ['enabled' => true, 'limit_value' => null, 'is_unlimited' => true],
            is_int($value) => ['enabled' => true, 'limit_value' => $value, 'is_unlimited' => false],
            default => ['enabled' => (bool) $value, 'limit_value' => null, 'is_unlimited' => false],
        };
    }
}
