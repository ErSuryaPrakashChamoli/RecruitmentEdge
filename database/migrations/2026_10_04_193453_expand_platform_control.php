<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SaaS-5 (expand): the platform control plane. Additive only.
     *
     * - support_access_grants (tenant-owned, existing): a grant can now be requested by a platform
     *   support operator and approved or denied by the tenant, with scopes, status and usage —
     *   requested grants have no start or end yet, so those become nullable.
     * - tenant_deletion_requests, compliance_exports (platform records about a tenant, tenant_id NOT
     *   NULL): they survive the tenant's purge — they are the platform's evidence of what it did.
     * - platform_events (platform): the operational event feed; tenant_id is the tenant an event is
     *   about, empty for platform-wide events.
     */
    public function up(): void
    {
        Schema::table('support_access_grants', function (Blueprint $table): void {
            $table->string('status', 20)->default('active')->after('platform_operator_id');
            $table->json('requested_scopes')->nullable()->after('reason');
            $table->json('scopes')->nullable()->after('requested_scopes');
            $table->unsignedSmallInteger('requested_minutes')->nullable()->after('scopes');
            $table->timestamp('requested_at')->nullable()->after('requested_minutes');
            $table->timestamp('decided_at')->nullable()->after('requested_at');
            $table->string('denial_reason', 255)->nullable()->after('decided_at');
            $table->string('revocation_reason', 255)->nullable()->after('revoked_by');
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('starts_at')->nullable()->change();
            $table->timestamp('expires_at')->nullable()->change();
            $table->index(['tenant_id', 'status'], 'support_access_grants_status_idx');
        });

        Schema::create('tenant_deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('status', 20);
            $table->boolean('is_open')->nullable();
            $table->string('reason', 255);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('purge_after')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('purge_started_at')->nullable();
            $table->timestamp('purge_completed_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->string('lease_owner', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('failures')->default(0);
            $table->json('progress')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'is_open'], 'tenant_deletion_requests_open_unique');
            $table->index(['status', 'purge_after'], 'tenant_deletion_requests_due_idx');
        });

        Schema::create('compliance_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('status', 20);
            $table->string('reason', 255);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->string('disk', 30);
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->json('manifest')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->foreignId('last_downloaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'compliance_exports_tenant_status_idx');
            $table->index(['status', 'expires_at'], 'compliance_exports_expiry_idx');
        });

        Schema::create('platform_events', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 64);
            $table->string('severity', 16);
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('title', 255);
            $table->json('context')->nullable();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->timestamp('occurred_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['severity', 'occurred_at'], 'platform_events_severity_idx');
            $table->index(['tenant_id', 'occurred_at'], 'platform_events_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_events');
        Schema::dropIfExists('compliance_exports');
        Schema::dropIfExists('tenant_deletion_requests');

        // Grants that never started (requests, denials) get dates again as already-ended, revoked
        // grants — under the SaaS-2 rules they can never open anything.
        DB::table('support_access_grants')->whereNull('starts_at')->orWhereNull('expires_at')->update([
            'starts_at' => DB::raw('COALESCE(starts_at, created_at)'),
            'expires_at' => DB::raw('COALESCE(expires_at, created_at)'),
            'revoked_at' => DB::raw('COALESCE(revoked_at, created_at)'),
        ]);

        Schema::table('support_access_grants', function (Blueprint $table): void {
            $table->dropIndex('support_access_grants_status_idx');
            $table->dropColumn(['status', 'requested_scopes', 'scopes', 'requested_minutes', 'requested_at', 'decided_at', 'denial_reason', 'revocation_reason', 'last_used_at', 'use_count']);
        });

        Schema::table('support_access_grants', function (Blueprint $table): void {
            $table->timestamp('starts_at')->nullable(false)->change();
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
