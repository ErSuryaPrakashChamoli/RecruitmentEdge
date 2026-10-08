<?php

namespace Tests;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * SaaS-1: the tenant a database test works in (the equivalent of Tenant #1). Tests that need
     * another tenant create it and enter it with TenantContext::run() / actInTenant().
     */
    protected ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme Hiring']);
            $this->actInTenant($this->tenant);
        }
    }

    /**
     * Makes $tenant the current tenant for the test's own code: TenantContext (models, roles,
     * jobs, tenant route defaults) and Filament's selected tenant (resource URLs, Livewire pages).
     */
    protected function actInTenant(Tenant $tenant): void
    {
        TenantContext::current()->setTenant($tenant);
        Filament::setTenant($tenant, isQuiet: true);
    }
}
