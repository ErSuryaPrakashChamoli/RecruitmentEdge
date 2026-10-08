<?php

namespace App\Services\Platform;

use App\Services\Tenancy\TenantSchema;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * SaaS-5: what a tenant purge deletes, and in which order — derived from the schema itself, so a
 * new tenant table is purged by construction (TenantSchema classifies every table).
 *
 * Purged: every tenant-owned table (TenantSchema::TENANT_TABLES), the tenant's provider delivery
 * callbacks (communication_webhook_events), and the tenant's side of the identity plane
 * (memberships, its roles and their assignments; role permissions cascade with the roles) — except
 * the retained tables (config platform.deletion.retain_tables: commercial and payment evidence, the
 * audit trail, the record of support access). Never: the global identities (users), platform
 * records (tenants, deletion requests, exports, events, prices), retained tables.
 *
 * Order: children before parents along every foreign key inside the purged set (deleting a parent
 * first would be refused by RESTRICT, or cascade unpredictably). Self-references and cycles are
 * broken by nulling their nullable columns first. The plan refuses to run when a retained or
 * platform table depends on a purged one in a way the purge would break.
 */
final class TenantPurgePlan
{
    /**
     * Identity-plane tables that carry the tenant (SaaS-1/2).
     */
    private const IDENTITY_TENANT_TABLES = ['tenant_memberships', 'model_has_roles', 'model_has_permissions', 'roles'];

    /**
     * Tables without a tenant id whose rows belong to a purged parent and go with it (ON DELETE
     * CASCADE): a tenant role's permissions. Any other dependency outside the purge refuses the plan.
     */
    private const CASCADED_WITH_PARENT = ['role_has_permissions'];

    /**
     * @param  list<string>  $tables  in deletion order
     * @param  array<string, list<string>>  $nullFirst  table => nullable columns set to null before deleting
     * @param  list<string>  $retained
     */
    private function __construct(public readonly array $tables, public readonly array $nullFirst, public readonly array $retained) {}

    public static function build(): self
    {
        $retained = array_values(array_intersect((array) config('platform.deletion.retain_tables', []), [...TenantSchema::TENANT_TABLES, ...TenantSchema::NULLABLE_TENANT_TABLES]));
        $purged = array_values(array_diff([...TenantSchema::TENANT_TABLES, 'communication_webhook_events', ...self::IDENTITY_TENANT_TABLES], $retained));
        $inSet = array_flip($purged);

        /** @var array<string, list<string>> $children parent => tables referencing it */
        $children = [];
        $nullFirst = array_fill_keys($purged, []);
        $foreignKeys = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (! str_starts_with($table, 'sqlite_')) {
                $foreignKeys[$table] = Schema::getForeignKeys($table);
            }
        }

        foreach ($purged as $table) {
            $columns = collect(Schema::getColumns($table))->keyBy('name');

            foreach ($foreignKeys[$table] ?? [] as $foreignKey) {
                $parent = self::bare((string) $foreignKey['foreign_table']);

                if ($parent === $table) {
                    foreach ($foreignKey['columns'] as $column) {
                        if ($columns[$column]['nullable'] ?? false) {
                            $nullFirst[$table][] = $column;
                        }
                    }

                    continue;
                }

                if (isset($inSet[$parent])) {
                    $children[$parent][] = $table;
                }
            }
        }

        // Nothing outside the purge may depend on a purged row in a way the purge would break.
        foreach ($foreignKeys as $table => $keys) {
            if (isset($inSet[$table])) {
                continue;
            }

            foreach ($keys as $foreignKey) {
                $parent = self::bare((string) $foreignKey['foreign_table']);
                $onDelete = strtolower((string) ($foreignKey['on_delete'] ?? 'restrict'));

                if (isset($inSet[$parent]) && $onDelete !== 'set null' && ! ($onDelete === 'cascade' && in_array($table, self::CASCADED_WITH_PARENT, true))) {
                    throw new RuntimeException("Purge plan refused: {$table} ({$onDelete}) depends on purged table {$parent}.");
                }
            }
        }

        $order = [];
        $state = [];
        $visit = function (string $table) use (&$visit, &$order, &$state, $children, $foreignKeys, &$nullFirst): void {
            if (($state[$table] ?? null) === 'done') {
                return;
            }

            $state[$table] = 'visiting';

            foreach (array_unique($children[$table] ?? []) as $child) {
                if (($state[$child] ?? null) === 'visiting') {
                    // A cycle: the child's references to this table are nulled before anything is deleted.
                    foreach ($foreignKeys[$child] as $foreignKey) {
                        if (self::bare((string) $foreignKey['foreign_table']) === $table) {
                            array_push($nullFirst[$child], ...$foreignKey['columns']);
                        }
                    }

                    continue;
                }

                $visit($child);
            }

            $state[$table] = 'done';
            $order[] = $table;
        };

        foreach ($purged as $table) {
            $visit($table);
        }

        return new self($order, array_filter(array_map(fn (array $columns): array => array_values(array_unique($columns)), $nullFirst)), $retained);
    }

    private static function bare(string $table): string
    {
        return str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
    }
}
