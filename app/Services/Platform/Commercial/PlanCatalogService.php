<?php

namespace App\Services\Platform\Commercial;

use App\Enums\Entitlement;
use App\Enums\EntitlementType;
use App\Enums\PlanVersionStatus;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-3: publishes the code-defined catalog (PlanCatalog) — idempotent and version-safe. A version
 * that exists is compared with its definition and never changed: a difference is refused ("publish
 * a new version"), so no tenant's behaviour changes because a definition was edited.
 */
class PlanCatalogService
{
    /**
     * @return array{published: list<string>, unchanged: list<string>}
     */
    public function sync(?User $operator = null): array
    {
        PlatformOperatorGate::assert($operator);
        $result = ['published' => [], 'unchanged' => []];

        TenantContext::current()->runWithoutTenant(function () use ($operator, &$result): void {
            foreach (PlanCatalog::definitions() as $code => $definition) {
                DB::transaction(function () use ($code, $definition, $operator, &$result): void {
                    $plan = Plan::query()->lockForUpdate()->firstOrCreate(['code' => $code], ['name' => $definition['name'], 'description' => $definition['description'], 'status' => $definition['status']]);
                    $plan->forceFill(['name' => $definition['name'], 'description' => $definition['description'], 'status' => $definition['status']])->save();

                    foreach ($definition['versions'] as $number => $values) {
                        $this->assertComplete($code, $number, $values);
                        $existing = PlanVersion::query()->where('plan_id', $plan->id)->where('version', $number)->with('entitlements')->first();

                        if ($existing !== null) {
                            $this->assertUnchanged($code, $existing, $values);
                            $result['unchanged'][] = "{$code} v{$number}";

                            continue;
                        }

                        $version = PlanVersion::query()->create(['plan_id' => $plan->id, 'version' => $number, 'status' => PlanVersionStatus::Published, 'published_at' => now()]);

                        foreach ($values as $key => $value) {
                            $version->entitlements()->create(['key' => $key, ...PlanCatalog::stored($value)]);
                        }

                        AuditLog::record($version, 'plan_version_published', null, ['plan' => $code, 'version' => $number, 'entitlements' => $values, 'by_user_id' => $operator?->getKey()]);
                        Log::notice('platform.plan_version_published', ['plan' => $code, 'version' => $number]);
                        $result['published'][] = "{$code} v{$number}";
                    }
                });
            }
        });

        return $result;
    }

    /**
     * Every registry key, with a value of its type — a version never leaves a key undefined, except
     * a key no plan grants yet (PlanCatalog::UNGRANTED), which stays denied while it is absent.
     *
     * @param  array<string, bool|int|string>  $values
     */
    private function assertComplete(string $code, int $number, array $values): void
    {
        foreach (Entitlement::cases() as $entitlement) {
            if (! array_key_exists($entitlement->value, $values) && in_array($entitlement, PlanCatalog::UNGRANTED, true)) {
                continue;
            }

            $value = $values[$entitlement->value] ?? null;
            $valid = $entitlement->type() === EntitlementType::Feature ? is_bool($value) : (is_int($value) && $value >= 0) || $value === PlanCatalog::UNLIMITED;

            if (! $valid) {
                throw new DomainException("Plan {$code} v{$number}: {$entitlement->value} needs a ".$entitlement->type()->value.' value.');
            }
        }

        if (array_diff(array_keys($values), array_map(fn (Entitlement $e): string => $e->value, Entitlement::cases())) !== []) {
            throw new DomainException("Plan {$code} v{$number} names a key outside the entitlement registry.");
        }
    }

    /**
     * @param  array<string, bool|int|string>  $values
     */
    private function assertUnchanged(string $code, PlanVersion $version, array $values): void
    {
        $stored = $version->entitlements->mapWithKeys(fn ($row): array => [$row->key => ['enabled' => (bool) $row->enabled, 'limit_value' => $row->limit_value, 'is_unlimited' => (bool) $row->is_unlimited]])->sortKeys()->all();
        $defined = collect($values)->map(fn (bool|int|string $value): array => PlanCatalog::stored($value))->sortKeys()->all();

        if ($stored != $defined) {
            throw new DomainException("Plan {$code} v{$version->version} is published and differs from its definition: publish v".($version->version + 1).' instead of editing it.');
        }
    }
}
