<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHUNK = 5000;

    /**
     * SaaS-2 (backfill + validate, step 2 of 3): the employee link and the access state move from
     * the identity (users) to its tenant memberships. Deterministic, batched by id range, resumable
     * (every statement can run again with the same result), and it never creates or deletes a
     * membership, a user or a role assignment.
     *
     * Mapping (docs/saas-2-migration-plan.md §2):
     * - Employee link: users.employee_id belongs to the membership of the tenant that holds that
     *   employee (SaaS-1 already mirrored it there). That membership becomes the person's default.
     * - Access state: SaaS-1's identity-wide state applied in every tenant, so it is copied to every
     *   membership that was not already revoked. Effective access is unchanged for every person in
     *   every tenant: nobody gains or loses access.
     *
     * Refuses to run (counts only, never data) while the mapping is not deterministic.
     */
    public function up(): void
    {
        $this->refuseUnlessMappable();

        $maxId = (int) DB::table('tenant_memberships')->max('id');

        for ($from = 1; $from <= $maxId; $from += self::CHUNK) {
            $to = $from + self::CHUNK - 1;
            $user = fn (string $column): string => "(select users.{$column} from users where users.id = tenant_memberships.user_id)";

            DB::table('tenant_memberships')
                ->whereBetween('id', [$from, $to])
                ->whereNull('employee_id')
                ->whereExists(fn ($query) => $query->from('users')
                    ->join('employees', 'employees.id', '=', 'users.employee_id')
                    ->whereColumn('users.id', 'tenant_memberships.user_id')
                    ->whereColumn('employees.tenant_id', 'tenant_memberships.tenant_id'))
                ->update(['employee_id' => DB::raw($user('employee_id'))]);

            DB::table('tenant_memberships')
                ->whereBetween('id', [$from, $to])
                ->where('status', '!=', 'revoked')
                ->update([
                    'status' => DB::raw($user('access_status')),
                    'status_changed_at' => DB::raw($user('access_changed_at')),
                    'status_changed_by' => DB::raw($user('access_changed_by')),
                    'status_reason' => DB::raw($user('access_reason')),
                    'status_source' => DB::raw($user('access_source')),
                    'revoked_roles' => DB::raw($user('revoked_roles')),
                ]);

            DB::table('tenant_memberships')->whereBetween('id', [$from, $to])->whereNull('joined_at')->update(['joined_at' => DB::raw('created_at')]);

            DB::table('tenant_memberships')
                ->whereBetween('id', [$from, $to])
                ->whereNotNull('employee_id')
                ->whereExists(fn ($query) => $query->from('users')->whereColumn('users.id', 'tenant_memberships.user_id')->whereColumn('users.employee_id', 'tenant_memberships.employee_id'))
                ->update(['is_default' => true]);
        }

        $this->refuseUnlessMoved();
    }

    /**
     * Structural rollback only: the users columns are untouched until step 3, so they remain the
     * pre-SaaS-2 source of truth. A data rollback is a backup restore (migration plan §4).
     */
    public function down(): void {}

    private function refuseUnlessMappable(): void
    {
        $problems = array_filter([
            // A login linked to an employee must be a member of that employee's tenant.
            'logins whose employee\'s tenant has no membership' => DB::table('users')
                ->join('employees', 'employees.id', '=', 'users.employee_id')
                ->whereNotExists(fn ($query) => $query->from('tenant_memberships')
                    ->whereColumn('tenant_memberships.user_id', 'users.id')
                    ->whereColumn('tenant_memberships.tenant_id', 'employees.tenant_id'))
                ->count(),
            // A membership's employee must be the login's employee (SaaS-1 kept them equal).
            'memberships linked to an employee other than the login\'s' => DB::table('tenant_memberships')
                ->join('users', 'users.id', '=', 'tenant_memberships.user_id')
                ->whereNotNull('tenant_memberships.employee_id')
                ->where(fn ($query) => $query->whereNull('users.employee_id')->orWhereColumn('users.employee_id', '!=', 'tenant_memberships.employee_id'))
                ->count(),
            'employees linked to more than one membership' => DB::table('tenant_memberships')
                ->whereNotNull('employee_id')
                ->groupBy('employee_id')
                ->havingRaw('count(*) > 1')
                ->get(['employee_id'])
                ->count(),
            'memberships with an unknown status' => DB::table('tenant_memberships')->whereNotIn('status', ['active', 'revoked', 'suspended'])->count(),
            'logins with an unknown access state' => DB::table('users')->whereNotIn('access_status', ['active', 'suspended', 'revoked'])->count(),
        ]);

        if ($problems !== []) {
            throw new RuntimeException('SaaS-2 identity backfill refused — resolve first: '.json_encode($problems));
        }
    }

    private function refuseUnlessMoved(): void
    {
        $problems = array_filter([
            'logins whose employee link was not moved' => DB::table('users')
                ->whereNotNull('users.employee_id')
                ->whereNotExists(fn ($query) => $query->from('tenant_memberships')
                    ->whereColumn('tenant_memberships.user_id', 'users.id')
                    ->whereColumn('tenant_memberships.employee_id', 'users.employee_id'))
                ->count(),
            'memberships whose access state differs from the login\'s' => DB::table('tenant_memberships')
                ->join('users', 'users.id', '=', 'tenant_memberships.user_id')
                ->where('tenant_memberships.status', '!=', 'revoked')
                ->whereColumn('tenant_memberships.status', '!=', 'users.access_status')
                ->count(),
        ]);

        if ($problems !== []) {
            throw new RuntimeException('SaaS-2 identity backfill did not validate: '.json_encode($problems));
        }
    }
};
