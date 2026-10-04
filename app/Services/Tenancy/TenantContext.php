<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\URL;

/**
 * SaaS-1: the one authoritative answer to "which tenant is this work for?".
 *
 * The tenant id lives in Laravel's Context under `tenant_id`, so it:
 * - is set explicitly — by the panel's tenant middleware (Filament tenancy, membership checked),
 *   the careers/portal/webhook resolvers, TenantContext::run() in commands and tenant jobs;
 * - travels inside every queued payload (jobs, queued listeners, notifications, mail, exports) and
 *   is restored when the worker hydrates Context for that job (TenantQueueGuard verifies it);
 * - is written to every log line as context, so logs carry the tenant dimension;
 * - fills the {tenant} parameter of tenant routes (panel, careers, portal) and Filament's selected
 *   tenant, so a link generated in a job, a mail or a command points into the right tenant
 *   (syncUrlDefaults()).
 *
 * There is no "all tenants" value and no fallback: without a tenant, tenant-owned models refuse
 * to query (TenantScope throws MissingTenantContext). Never resolve the tenant from Host, from a
 * request parameter that was not checked against a membership or a signed record, or from
 * "the first tenant".
 */
class TenantContext
{
    public const CONTEXT_KEY = 'tenant_id';

    private ?Tenant $memo = null;

    public static function current(): self
    {
        return app(self::class);
    }

    public function setTenant(Tenant|int $tenant): void
    {
        $model = $tenant instanceof Tenant ? $tenant : Tenant::query()->find($tenant);

        if ($model === null) {
            throw new MissingTenantContext('The tenant does not exist.');
        }

        $this->memo = $model;
        Context::add(self::CONTEXT_KEY, (int) $model->getKey());
        $this->syncUrlDefaults();
    }

    public function tenant(): ?Tenant
    {
        $id = $this->id();

        if ($id === null) {
            $this->memo = null;

            return null;
        }

        if ($this->memo === null || (int) $this->memo->getKey() !== $id) {
            $this->memo = Tenant::query()->find($id);
        }

        return $this->memo;
    }

    public function id(): ?int
    {
        $id = Context::get(self::CONTEXT_KEY);

        return $id === null ? null : (int) $id;
    }

    public function hasTenant(): bool
    {
        return $this->id() !== null;
    }

    public function requireId(): int
    {
        return $this->id() ?? throw MissingTenantContext::forOperation('tenant-owned work');
    }

    public function requireTenant(): Tenant
    {
        return $this->tenant() ?? throw MissingTenantContext::forOperation('tenant-owned work');
    }

    public function clear(): void
    {
        $this->memo = null;
        Context::forget(self::CONTEXT_KEY);
        $this->syncUrlDefaults();
    }

    /**
     * The URL generator is shared by the whole process (a queue worker runs many tenants' jobs),
     * so the {tenant} route default always follows the context — and is removed with it, so a tenant
     * link cannot be generated without a tenant.
     */
    public function syncUrlDefaults(): void
    {
        $tenant = $this->tenant();

        URL::defaults(['tenant' => $tenant?->slug]);

        if (Filament::getTenant()?->isNot($tenant) ?? $tenant !== null) {
            Filament::setTenant($tenant, isQuiet: true);
        }
    }

    /**
     * Runs the callback inside the given tenant, then restores whatever context was active before
     * (including none), even when the callback throws.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Tenant|int $tenant, callable $callback): mixed
    {
        $previous = $this->id();
        $previousMemo = $this->memo;
        $this->setTenant($tenant);

        try {
            return $callback();
        } finally {
            $this->restore($previous, $previousMemo);
        }
    }

    /**
     * Runs platform-level work (no tenant at all), then restores the previous context.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runWithoutTenant(callable $callback): mixed
    {
        $previous = $this->id();
        $previousMemo = $this->memo;
        $this->clear();

        try {
            return $callback();
        } finally {
            $this->restore($previous, $previousMemo);
        }
    }

    private function restore(?int $id, ?Tenant $memo): void
    {
        if ($id === null) {
            $this->clear();

            return;
        }

        $this->memo = $memo !== null && (int) $memo->getKey() === $id ? $memo : null;
        Context::add(self::CONTEXT_KEY, $id);
        $this->syncUrlDefaults();
    }
}
