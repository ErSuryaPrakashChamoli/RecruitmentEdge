<?php

namespace App\Services\Entitlements;

use App\Enums\Entitlement;
use App\Enums\EntitlementType;

/**
 * SaaS-3: what a tenant may do for one registry key right now, and where that came from
 * ('plan', 'override', or 'missing' — the key is not granted at all, which is a denial).
 */
final readonly class EffectiveEntitlement
{
    public function __construct(
        public Entitlement $entitlement,
        public bool $enabled,
        public ?int $limit,
        public bool $unlimited,
        public string $source,
    ) {}

    public static function denied(Entitlement $entitlement, string $source = 'missing'): self
    {
        return new self($entitlement, false, null, false, $source);
    }

    /**
     * @param  array{enabled: bool, limit_value: int|null, is_unlimited: bool}  $value
     */
    public static function fromValue(Entitlement $entitlement, array $value, string $source): self
    {
        if ($entitlement->type() === EntitlementType::Feature) {
            return new self($entitlement, (bool) $value['enabled'], null, false, $source);
        }

        // A limit is granted only when it is enabled and is either unlimited or a definite number.
        $unlimited = (bool) $value['enabled'] && (bool) $value['is_unlimited'];
        $limit = $unlimited || $value['limit_value'] === null ? null : (int) $value['limit_value'];

        return new self($entitlement, (bool) $value['enabled'] && ($unlimited || $limit !== null), $limit, $unlimited, $source);
    }

    /**
     * Whether $amount more fits next to $usage.
     */
    public function allowsAdding(int $usage, int $amount = 1): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return $this->entitlement->type() === EntitlementType::Feature || $this->unlimited || $usage + $amount <= (int) $this->limit;
    }
}
