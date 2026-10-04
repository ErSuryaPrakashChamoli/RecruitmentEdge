<?php

namespace App\Services\Tenancy;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS-1: read-only proof of tenant integrity across the whole database (platform-level, so it
 * reads every tenant's rows on purpose — counts only, never data). Used to validate the Tenant #1
 * backfill before the enforcing migration, after any data repair, and by tenancy:verify.
 *
 * A clean database reports zero on every check:
 * - no tenant-owned row without a tenant;
 * - no reference (foreign key, composite or application-checked) pointing into another tenant;
 * - no polymorphic owner or subject in another tenant;
 * - no role without a tenant, no role assignment outside its role's tenant, no staff role in a
 *   tenant the person is not a member of, no membership linked to another tenant's employee.
 */
class TenancyVerifier
{
    /**
     * Polymorphic columns whose stored type is an alias rather than a model class.
     *
     * @var array<string, array<string, string>>
     */
    private const MORPH_ALIASES = [
        'ai_document_chunks.source_type' => ['document' => 'ai_documents', 'knowledge_article' => 'ai_knowledge_articles'],
    ];

    /**
     * @var list<array{0: string, 1: string, 2: string}> [table, type column, id column]
     */
    private const MORPHS = [
        ['ai_conversations', 'context_type', 'context_id'],
        ['ai_document_chunks', 'source_type', 'source_id'],
        ['audit_logs', 'auditable_type', 'auditable_id'],
        ['audit_logs', 'actor_type', 'actor_id'],
        ['automation_action_executions', 'target_type', 'target_id'],
        ['automation_executions', 'subject_type', 'subject_id'],
        ['automation_rules', 'scope_type', 'scope_id'],
        ['candidate_communication_preferences', 'updated_by_type', 'updated_by_id'],
        ['candidate_timeline_events', 'actor_type', 'actor_id'],
        ['candidate_timeline_events', 'subject_type', 'subject_id'],
        ['hiring_memory_records', 'subject_type', 'subject_id'],
        ['hiring_outcomes', 'source_type', 'source_id'],
        ['hiring_risks', 'subject_type', 'subject_id'],
        ['intelligence_evidence', 'owner_type', 'owner_id'],
        ['intelligence_evidence', 'source_type', 'source_id'],
        ['recruiter_actions', 'subject_type', 'subject_id'],
    ];

    /**
     * @return array<string, int> check => offending rows (0 = clean)
     */
    public function report(): array
    {
        return [
            ...$this->missingTenants(),
            ...$this->crossTenantReferences(),
            ...$this->crossTenantMorphs(),
            ...$this->identityLinks(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function violations(): array
    {
        return array_filter($this->report(), fn (int $count): bool => $count > 0);
    }

    /**
     * @return array<string, int>
     */
    private function missingTenants(): array
    {
        $checks = [];

        foreach ([...TenantSchema::TENANT_TABLES, 'roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            $checks["missing tenant: {$table}"] = DB::table($table)->whereNull('tenant_id')->count();
        }

        return $checks;
    }

    /**
     * @return array<string, int>
     */
    private function crossTenantReferences(): array
    {
        $checks = [];
        $references = array_merge_recursive(TenantSchema::REFERENCES, TenantSchema::COMPOSITE_REFERENCES, ['model_has_roles' => ['role_id' => 'roles']]);

        foreach ($references as $table => $columns) {
            foreach ($columns as $column => $parent) {
                $checks["cross-tenant reference: {$table}.{$column} → {$parent}"] = DB::table("{$table} as child")
                    ->join("{$parent} as parent", 'parent.id', '=', "child.{$column}")
                    ->whereColumn('parent.tenant_id', '!=', 'child.tenant_id')
                    ->count();
            }
        }

        return $checks;
    }

    /**
     * @return array<string, int>
     */
    private function crossTenantMorphs(): array
    {
        $checks = [];

        foreach (self::MORPHS as [$table, $typeColumn, $idColumn]) {
            $types = DB::table($table)->whereNotNull($typeColumn)->distinct()->pluck($typeColumn);

            foreach ($types as $type) {
                $target = $this->morphTable($table, $typeColumn, (string) $type);

                if ($target === null || ! (TenantSchema::isTenantOwned($target) || $target === 'roles')) {
                    continue;
                }

                $checks["cross-tenant {$table}.{$typeColumn} → {$target}"] = DB::table("{$table} as child")
                    ->where("child.{$typeColumn}", $type)
                    ->whereNotNull('child.tenant_id')
                    ->join("{$target} as target", 'target.id', '=', "child.{$idColumn}")
                    ->whereColumn('target.tenant_id', '!=', 'child.tenant_id')
                    ->count();
            }
        }

        return $checks;
    }

    private function morphTable(string $table, string $column, string $type): ?string
    {
        if (isset(self::MORPH_ALIASES["{$table}.{$column}"][$type])) {
            return self::MORPH_ALIASES["{$table}.{$column}"][$type];
        }

        $class = Model::getActualClassNameForMorph($type);

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $tableName = (new $class)->getTable();

        return Schema::hasColumn($tableName, 'tenant_id') ? $tableName : null;
    }

    /**
     * @return array<string, int>
     */
    private function identityLinks(): array
    {
        $userMorph = (new User)->getMorphClass();

        return [
            'role without a tenant' => DB::table('roles')->whereNull('tenant_id')->count(),
            'staff role in a tenant the person is not a member of' => DB::table('model_has_roles')
                ->where('model_type', $userMorph)
                ->whereNotExists(fn ($query) => $query->from('tenant_memberships')
                    ->whereColumn('tenant_memberships.user_id', 'model_has_roles.model_id')
                    ->whereColumn('tenant_memberships.tenant_id', 'model_has_roles.tenant_id'))
                ->count(),
            // SaaS-2: only the three access states exist; an employee record has one login at most.
            'membership with an unknown access state' => DB::table('tenant_memberships')->whereNotIn('status', ['active', 'suspended', 'revoked'])->count(),
            'employee record linked to more than one membership' => DB::table('tenant_memberships')->whereNotNull('employee_id')->groupBy('employee_id')->havingRaw('count(*) > 1')->get(['employee_id'])->count(),
            'membership linked to another tenant\'s employee' => DB::table('tenant_memberships')
                ->join('employees', 'employees.id', '=', 'tenant_memberships.employee_id')
                ->whereColumn('employees.tenant_id', '!=', 'tenant_memberships.tenant_id')
                ->count(),
        ];
    }
}
