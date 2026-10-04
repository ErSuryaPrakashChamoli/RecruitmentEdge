<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SaaS-3 (expand, step 1 of 2): the commercial control plane. Additive only.
     *
     * Platform records (no tenant): plans → plan_versions (immutable once published) →
     * plan_entitlements (one value per registry key: a feature on/off, or a limit — a number or
     * explicitly unlimited).
     *
     * Tenant-owned: tenant_plan_assignments (the tenant's pinned plan version; exactly one current
     * row — unique (tenant_id, is_current), history keeps NULL) and tenant_entitlement_overrides
     * (an explicit, time-bound replacement of one key; one current row per key).
     *
     * Tenants gain their trial window, the reason for their status, an entitlement version (bumped
     * by every commercial change — the cache key) and their provisioning state. A membership can be
     * the tenant's owner (one per tenant); an invitation can grant that ownership.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 16);
            $table->timestamps();
        });

        Schema::create('plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16);
            $table->timestamp('published_at')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'version'], 'plan_versions_plan_version_unique');
        });

        Schema::create('plan_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_version_id')->constrained('plan_versions')->restrictOnDelete();
            $table->string('key', 64);
            $table->boolean('enabled');
            $table->unsignedInteger('limit_value')->nullable();
            $table->boolean('is_unlimited')->default(false);
            $table->timestamps();

            $table->unique(['plan_version_id', 'key'], 'plan_entitlements_version_key_unique');
        });

        Schema::create('tenant_plan_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained('plan_versions')->restrictOnDelete();
            $table->boolean('is_current')->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('source', 30);
            $table->string('reason', 255)->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'is_current'], 'tenant_plan_assignments_current_unique');
            $table->index(['tenant_id', 'effective_from'], 'tenant_plan_assignments_history_idx');
        });

        Schema::create('tenant_entitlement_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('key', 64);
            $table->boolean('enabled');
            $table->unsignedInteger('limit_value')->nullable();
            $table->boolean('is_unlimited')->default(false);
            $table->boolean('is_current')->nullable();
            $table->string('reason', 255);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'key', 'is_current'], 'tenant_overrides_current_unique');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('status_reason', 64)->nullable()->after('status_changed_at');
            $table->timestamp('trial_started_at')->nullable()->after('status_reason');
            $table->timestamp('trial_ends_at')->nullable()->after('trial_started_at');
            $table->unsignedInteger('entitlement_version')->default(1)->after('trial_ends_at');
            $table->timestamp('provisioned_at')->nullable()->after('entitlement_version');
            $table->json('provisioning_state')->nullable()->after('provisioned_at');
            $table->string('provisioning_error', 255)->nullable()->after('provisioning_state');
            $table->index(['status', 'trial_ends_at'], 'tenants_status_trial_idx');
        });

        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->boolean('is_owner')->nullable()->after('is_default');
            $table->unique(['tenant_id', 'is_owner'], 'tenant_memberships_owner_unique');
        });

        Schema::table('tenant_invitations', function (Blueprint $table): void {
            $table->boolean('grants_ownership')->default(false)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_invitations', function (Blueprint $table): void {
            $table->dropColumn('grants_ownership');
        });

        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->dropUnique('tenant_memberships_owner_unique');
            $table->dropColumn('is_owner');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex('tenants_status_trial_idx');
            $table->dropColumn(['status_reason', 'trial_started_at', 'trial_ends_at', 'entitlement_version', 'provisioned_at', 'provisioning_state', 'provisioning_error']);
        });

        Schema::dropIfExists('tenant_entitlement_overrides');
        Schema::dropIfExists('tenant_plan_assignments');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('plan_versions');
        Schema::dropIfExists('plans');
    }
};
