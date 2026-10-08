<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SaaS-2 (expand, step 1 of 3): the tenant membership becomes the access relationship between a
     * global identity and a tenant.
     *
     * - tenant_memberships carries the access state of that membership (active / suspended /
     *   revoked, who changed it and why, the roles a revocation removed) and the person's default
     *   tenant preference. The employee link already lives here (SaaS-1 mirror); step 2 makes it
     *   the source.
     * - tenants.mfa_required: the tenant's own MFA policy (every member must use MFA).
     * - users.disabled_at: a platform-level lock of the whole identity (not a tenant decision).
     * - tenant_invitations: the only way a membership is created (hashed single-use tokens).
     * - platform_operators / support_access_grants: the platform plane's foundation. Neither grants
     *   any access to tenant data in SaaS-2.
     *
     * Additive only: no existing column changes.
     */
    public function up(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->timestamp('status_changed_at')->nullable()->after('status');
            $table->foreignId('status_changed_by')->nullable()->after('status_changed_at')->constrained('users')->nullOnDelete();
            $table->string('status_reason', 255)->nullable()->after('status_changed_by');
            $table->string('status_source', 30)->nullable()->after('status_reason');
            $table->json('revoked_roles')->nullable()->after('status_source');
            $table->boolean('is_default')->default(false)->after('revoked_roles');
            $table->timestamp('joined_at')->nullable()->after('is_default');
            $table->timestamp('last_selected_at')->nullable()->after('joined_at');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('mfa_required')->default(false)->after('status_changed_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('disabled_at')->nullable()->after('remember_token');
            $table->string('disabled_reason', 255)->nullable()->after('disabled_at');
        });

        Schema::create('tenant_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->json('role_ids');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('source', 30);
            $table->char('token_hash', 64)->unique();
            $table->string('status', 16);
            $table->timestamp('expires_at');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('send_count')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoked_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'expires_at'], 'tenant_invitations_status_idx');
            $table->index(['tenant_id', 'email'], 'tenant_invitations_email_idx');
        });

        Schema::create('platform_operators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32);
            $table->string('reason', 255);
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'role'], 'platform_operators_user_role_unique');
        });

        Schema::create('support_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('platform_operator_id')->constrained('platform_operators')->restrictOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255);
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'platform_operator_id', 'expires_at'], 'support_access_grants_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_access_grants');
        Schema::dropIfExists('platform_operators');
        Schema::dropIfExists('tenant_invitations');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['disabled_at', 'disabled_reason']);
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('mfa_required');
        });

        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropColumn(['status_changed_at', 'status_reason', 'status_source', 'revoked_roles', 'is_default', 'joined_at', 'last_selected_at']);
        });
    }
};
