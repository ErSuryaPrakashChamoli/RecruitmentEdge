<?php

namespace Tests\Feature\Api;

use App\Enums\ApiScope;
use App\Enums\Entitlement;
use App\Enums\RequisitionStatus;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\JobPosting;
use App\Models\RecruitmentRequisition;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Api\ApiCredentialService;
use App\Services\Distribution\JobDistributionService;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use App\Services\Tenancy\TenantContext;
use Tests\Feature\IdentityAccess\IdentityWorld;

/**
 * SaaS-6 fixtures: the SaaS-2 identity world (acme and beta, each with its CHRO) with the API and
 * webhooks enabled for both by platform override (no plan grants them yet, D-S6-O11), and one
 * all-scope credential per tenant, issued by its CHRO.
 */
final class ApiWorld
{
    public IdentityWorld $identity;

    public string $tokenA;

    public string $tokenB;

    public static function build(Tenant $acme): self
    {
        $world = new self;
        $world->identity = IdentityWorld::build($acme);

        foreach ([$world->identity->acme, $world->identity->beta] as $tenant) {
            self::enable($tenant);
        }

        $world->tokenA = self::issue($world->identity->acme, $world->identity->adminA, 'Acme ATS sync');
        $world->tokenB = self::issue($world->identity->beta, $world->identity->adminB, 'Beta ATS sync');

        // The overrides bumped the tenants' entitlement version: the context must not hold the stale row.
        TenantContext::current()->setTenant($acme->fresh());

        return $world;
    }

    public static function enable(Tenant $tenant, bool $api = true, bool $webhooks = true): void
    {
        app(EntitlementOverrideService::class)->set($tenant->fresh(), Entitlement::ApiAccess, $api, 'Pilot');
        app(EntitlementOverrideService::class)->set($tenant->fresh(), Entitlement::IntegrationsWebhooks, $webhooks, 'Pilot');
    }

    /**
     * @param  list<ApiScope>|null  $scopes
     */
    public static function issue(Tenant $tenant, User $actor, string $name, ?array $scopes = null, int $days = 90): string
    {
        return TenantContext::current()->run($tenant->fresh(), fn (): string => app(ApiCredentialService::class)->issue($actor, $name, $scopes ?? ApiScope::cases(), $days)['token']);
    }

    /**
     * A published job posting on an open requisition, in $tenant.
     */
    public static function livePosting(Tenant $tenant, string $title = 'Field Sales Executive'): JobPosting
    {
        return TenantContext::current()->run($tenant, function () use ($title): JobPosting {
            CandidateSource::query()->where('code', CandidateSource::CODE_WEBSITE)->exists() || CandidateSource::factory()->create(['name' => 'Website', 'code' => CandidateSource::CODE_WEBSITE]);
            $recruiter = Employee::factory()->create();
            User::factory()->create(['employee_id' => $recruiter->id]);
            $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'manager_id' => $recruiter->id]);
            $distribution = app(JobDistributionService::class);
            $posting = $distribution->savePosting($requisition, ['title' => $title, 'description' => str_repeat('Drive enterprise sales across the region. ', 3)]);
            $distribution->publish($posting, ['career_site']);

            return $posting->fresh();
        });
    }

    /**
     * @return array<string, string>
     */
    public static function headers(string $token, array $extra = []): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', ...$extra];
    }
}
