<?php

namespace App\Services\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS-1: assigns the existing single organisation's rows to Tenant #1 (the backfill step of
 * expand → backfill → validate → contract).
 *
 * - Batched: each statement updates at most CHUNK rows (an id or key range), never a whole large
 *   table in one transaction or lock.
 * - Resumable and idempotent: only rows whose tenant_id is still null are touched, so an
 *   interrupted run is simply run again.
 * - Business codes, ids and timestamps are never rewritten.
 */
class TenantBackfill
{
    public const CHUNK = 5000;

    /**
     * Tables without an integer id, backfilled by ranges of a leading integer key instead.
     *
     * @var array<string, string>
     */
    private const RANGE_KEYS = [
        'employee_hierarchy' => 'ancestor_id',
        'recruitment_requisition_recruiters' => 'requisition_id',
        'model_has_roles' => 'model_id',
        'model_has_permissions' => 'model_id',
    ];

    /**
     * Whether the database already holds an organisation (an upgrade), as opposed to a fresh
     * install, which gets its tenants from provisioning instead.
     *
     * @param  list<string>  $tables
     */
    public function hasOrganisationData(array $tables): bool
    {
        foreach (['users', ...$tables] as $table) {
            if (DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tables
     * @param  (Closure(string, int): void)|null  $progress  called after each table with the rows assigned
     * @return array<string, int>
     */
    public function run(int $tenantId, array $tables, ?Closure $progress = null): array
    {
        $assigned = [];

        foreach ($tables as $table) {
            $assigned[$table] = $this->backfillTable($table, $tenantId);
            $progress?->__invoke($table, $assigned[$table]);
        }

        $assigned['tenant_memberships'] = $this->createMemberships($tenantId);
        $progress?->__invoke('tenant_memberships', $assigned['tenant_memberships']);

        return $assigned;
    }

    private function backfillTable(string $table, int $tenantId): int
    {
        if (Schema::hasColumn($table, 'id') && $this->hasIntegerKey($table, 'id')) {
            return $this->backfillByRange($table, 'id', $tenantId);
        }

        if (isset(self::RANGE_KEYS[$table])) {
            return $this->backfillByRange($table, self::RANGE_KEYS[$table], $tenantId);
        }

        return $this->backfillByBatches($table, $tenantId);
    }

    private function backfillByRange(string $table, string $key, int $tenantId): int
    {
        $min = DB::table($table)->whereNull('tenant_id')->min($key);
        $max = DB::table($table)->whereNull('tenant_id')->max($key);

        if ($min === null || $max === null) {
            return 0;
        }

        $updated = 0;

        for ($from = (int) $min; $from <= (int) $max; $from += self::CHUNK) {
            $updated += DB::table($table)
                ->whereNull('tenant_id')
                ->whereBetween($key, [$from, $from + self::CHUNK - 1])
                ->update(['tenant_id' => $tenantId]);
        }

        return $updated;
    }

    /**
     * For a table keyed by a string (notifications: uuid): fetch a batch of keys, update them.
     */
    private function backfillByBatches(string $table, int $tenantId): int
    {
        $updated = 0;

        do {
            $ids = DB::table($table)->whereNull('tenant_id')->orderBy('id')->limit(self::CHUNK)->pluck('id');

            if ($ids->isNotEmpty()) {
                $updated += DB::table($table)->whereIn('id', $ids->all())->update(['tenant_id' => $tenantId]);
            }
        } while ($ids->count() === self::CHUNK);

        return $updated;
    }

    /**
     * Every existing staff identity becomes a member of Tenant #1, linked to the employee it
     * already has (users.employee_id). Existing memberships are left untouched.
     */
    private function createMemberships(int $tenantId): int
    {
        $created = 0;

        DB::table('users')->select(['id', 'employee_id'])->orderBy('id')->chunkById(self::CHUNK, function ($users) use ($tenantId, &$created): void {
            $existing = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->whereIn('user_id', $users->pluck('id')->all())->pluck('user_id')->flip();
            $now = now();

            $rows = $users->reject(fn ($user): bool => $existing->has($user->id))
                ->map(fn ($user): array => [
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'employee_id' => $user->employee_id,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all();

            if ($rows !== []) {
                DB::table('tenant_memberships')->insert($rows);
                $created += count($rows);
            }
        });

        return $created;
    }

    private function hasIntegerKey(string $table, string $column): bool
    {
        return in_array(Schema::getColumnType($table, $column), ['integer', 'bigint', 'int'], true);
    }
}
