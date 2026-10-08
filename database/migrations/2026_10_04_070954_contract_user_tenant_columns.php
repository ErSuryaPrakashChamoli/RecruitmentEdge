<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SaaS-2 (contract, step 3 of 3): the identity stops carrying tenant facts. The employee link
     * and the access state now live only on tenant_memberships (step 2 moved and validated them),
     * so the old users columns are dropped — a query that still names them fails loudly instead of
     * silently reading a stale, tenant-less value.
     *
     * Refuses to run while any login's employee link or access state is not on its membership.
     * One login per employee record stays a database rule (unique tenant_memberships.employee_id).
     */
    public function up(): void
    {
        $unmoved = DB::table('users')
            ->whereNotNull('users.employee_id')
            ->whereNotExists(fn ($query) => $query->from('tenant_memberships')
                ->whereColumn('tenant_memberships.user_id', 'users.id')
                ->whereColumn('tenant_memberships.employee_id', 'users.employee_id'))
            ->count();

        if ($unmoved > 0) {
            throw new RuntimeException("SaaS-2 contract refused: {$unmoved} login(s) whose employee link is not on a membership. Run the step 2 migration first.");
        }

        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->unique('employee_id', 'tenant_memberships_employee_unique');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_access_status');
            $table->dropForeign(['employee_id']);
            $table->dropForeign(['access_changed_by']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['employee_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['employee_id', 'access_status', 'access_changed_at', 'access_changed_by', 'access_reason', 'access_source', 'revoked_roles']);
        });
    }

    /**
     * Development only: re-creates the columns and copies the default membership's link and state
     * back. Not a data rollback (one identity may now hold several memberships): restore the backup.
     */
    public function down(): void
    {
        // The foreign key on employee_id needs an index of its own once the unique key goes (MySQL);
        // done first, so a failure cannot leave users half restored.
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->index('employee_id', 'tenant_memberships_employee_idx');
        });

        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->dropUnique('tenant_memberships_employee_unique');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->unique()->after('id')->constrained()->nullOnDelete();
            $table->string('access_status', 20)->default('active')->after('employee_id');
            $table->timestamp('access_changed_at')->nullable()->after('access_status');
            $table->foreignId('access_changed_by')->nullable()->after('access_changed_at')->constrained('users')->nullOnDelete();
            $table->string('access_reason', 255)->nullable()->after('access_changed_by');
            $table->string('access_source', 30)->nullable()->after('access_reason');
            $table->json('revoked_roles')->nullable()->after('access_source');
            $table->index('access_status', 'users_access_status');
        });

        DB::table('tenant_memberships')->where('is_default', true)->orderBy('id')->each(function (object $membership): void {
            DB::table('users')->where('id', $membership->user_id)->update([
                'employee_id' => $membership->employee_id,
                'access_status' => $membership->status,
                'access_changed_at' => $membership->status_changed_at,
                'access_changed_by' => $membership->status_changed_by,
                'access_reason' => $membership->status_reason,
                'access_source' => $membership->status_source,
                'revoked_roles' => $membership->revoked_roles,
            ]);
        });
    }
};
