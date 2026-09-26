<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.4: an explicit, authoritative access state per staff login (active / suspended /
 * revoked), separate from employment. Additive: every existing user starts as active, so deploying
 * changes nobody's access; identity:reconcile-access then maps inactive and separated employees
 * (dry run first). session_epoch lets a revocation or "sign out everywhere" invalidate every
 * session regardless of the session driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('access_status', 20)->default('active')->after('employee_id');
            $table->timestamp('access_changed_at')->nullable()->after('access_status');
            $table->foreignId('access_changed_by')->nullable()->after('access_changed_at')->constrained('users')->nullOnDelete();
            $table->string('access_reason', 255)->nullable()->after('access_changed_by');
            $table->json('revoked_roles')->nullable()->after('access_reason');
            $table->unsignedInteger('session_epoch')->default(0)->after('remember_token');
            $table->timestamp('last_login_at')->nullable()->after('session_epoch');

            $table->index('access_status', 'users_access_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_access_status');
            $table->dropConstrainedForeignId('access_changed_by');
            $table->dropColumn(['access_status', 'access_changed_at', 'access_reason', 'revoked_roles', 'session_epoch', 'last_login_at']);
        });
    }
};
