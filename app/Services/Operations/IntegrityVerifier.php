<?php

namespace App\Services\Operations;

use App\Services\Tenancy\TenancyVerifier;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SaaS-7 (C12): read-only validation of a database after a restore (or any repair, or a migration
 * rehearsal): the checks the rehearsals ran by hand, as one command (ops:verify-integrity).
 *
 * - foreign keys: rows whose reference points at no parent, for every declared foreign key (a
 *   restore taken table by table, or loaded with foreign_key_checks off, can leave them);
 * - tenancy: every TenancyVerifier check (tenant ids, cross-tenant references and morphs, roles);
 * - schema: migrations on disk that have not run;
 * - audit: whether the append-only triggers are installed.
 *
 * Production readiness: also the SaaS state a restore must bring back whole —
 * - failures (states the services never produce): a deleted tenant without its purge, a purge
 *   without its deleted tenant, a tenant pending deletion without an open request, a closed request
 *   still marked open; a paid invoice with an amount due; a refund larger than its payment; an
 *   encrypted value the configured keys cannot read (a restore with the wrong APP_KEY);
 * - warnings (for an operator to look at, not wrong data): usable tenants without a plan (every
 *   capability denied) or without an active owner; live API credentials whose owner is no longer an
 *   active member (refused at use); deliveries, inbound events, purges and support grants left past
 *   their claim, lease or expiry (the sweeps pick them up); the audit trail not append-only;
 * - the database: driver, version and the session's offset from UTC.
 *
 * Read-only and repeatable: it never repairs, deletes or changes anything. Counts only, never row
 * contents. Platform-level by nature (reads every tenant's counts).
 */
class IntegrityVerifier
{
    private const array USABLE = ['trial', 'active', 'past_due'];

    public function __construct(
        private readonly TenancyVerifier $tenancy,
        private readonly AuditProtection $audit,
        private readonly EncryptedColumns $encrypted,
        private readonly DatabaseClock $clock,
    ) {}

    /**
     * @return array{ok: bool, database: array{driver: string, version: string, utc_offset_minutes: int|null}, foreign_keys: array{checked: int, violations: array<string, int>}, tenancy: array{checks: int, violations: array<string, int>}, saas: array{failures: array<string, int>, warnings: array<string, int>}, encryption: array{values: int, unreadable: int}, pending_migrations: list<string>, audit_protected: bool}
     */
    public function report(): array
    {
        $database = $this->database();
        $foreignKeys = $this->foreignKeys();
        $tenancy = $this->tenancy->report();
        $tenancyViolations = array_filter($tenancy, fn (int $count): bool => $count > 0);
        $pending = $this->pendingMigrations();

        try {
            $auditProtected = $this->audit->installed();
        } catch (Throwable) {
            $auditProtected = false;
        }

        $saas = $this->saasState($auditProtected);
        $encryption = collect($this->encrypted->reencrypt(dryRun: true))
            ->reduce(fn (array $sum, array $table): array => ['values' => $sum['values'] + $table['values'], 'unreadable' => $sum['unreadable'] + $table['unreadable']], ['values' => 0, 'unreadable' => 0]);

        return [
            'ok' => $foreignKeys['violations'] === [] && $tenancyViolations === [] && $pending === [] && $saas['failures'] === [] && $encryption['unreadable'] === 0,
            'database' => $database,
            'foreign_keys' => $foreignKeys,
            'tenancy' => ['checks' => count($tenancy), 'violations' => $tenancyViolations],
            'saas' => $saas,
            'encryption' => $encryption,
            'pending_migrations' => $pending,
            'audit_protected' => $auditProtected,
        ];
    }

    /**
     * @return array{driver: string, version: string, utc_offset_minutes: int|null}
     */
    private function database(): array
    {
        $db = DB::connection();

        return [
            'driver' => $db->getDriverName(),
            'version' => (string) $db->scalar($db->getDriverName() === 'sqlite' ? 'select sqlite_version()' : 'select version()'),
            'utc_offset_minutes' => $this->clock->utcOffsetMinutes(),
        ];
    }

    /**
     * @return array{failures: array<string, int>, warnings: array<string, int>}
     */
    private function saasState(bool $auditProtected): array
    {
        $now = now();
        $count = fn (Builder $query): int => $query->count();
        $usableTenants = fn (): Builder => DB::table('tenants as t')->whereIn('t.status', self::USABLE);

        $failures = [
            'platform.deleted_tenant_without_purge' => $count(DB::table('tenants as t')->where('t.status', 'deleted')
                ->whereNotExists(fn (Builder $q) => $q->from('tenant_deletion_requests as r')->whereColumn('r.tenant_id', 't.id')->where('r.status', 'purged'))),
            'platform.purged_request_for_tenant_not_deleted' => $count(DB::table('tenant_deletion_requests as r')->where('r.status', 'purged')
                ->whereNotExists(fn (Builder $q) => $q->from('tenants as t')->whereColumn('t.id', 'r.tenant_id')->where('t.status', 'deleted'))),
            'platform.deletion_pending_without_open_request' => $count(DB::table('tenants as t')->where('t.status', 'deletion_pending')
                ->whereNotExists(fn (Builder $q) => $q->from('tenant_deletion_requests as r')->whereColumn('r.tenant_id', 't.id')->where('r.is_open', true))),
            'platform.closed_request_still_open' => $count(DB::table('tenant_deletion_requests')->where('is_open', true)->whereIn('status', ['cancelled', 'purged'])),
            'billing.paid_invoice_with_amount_due' => $count(DB::table('billing_invoices')->where('status', 'paid')->where('amount_due_minor', '<>', 0)),
            'billing.refund_exceeds_payment' => $count(DB::table('billing_payments')->whereColumn('amount_refunded_minor', '>', 'amount_minor')),
        ];

        $warnings = [
            'commercial.usable_tenant_without_plan' => $count($usableTenants()
                ->whereNotExists(fn (Builder $q) => $q->from('tenant_plan_assignments as a')->whereColumn('a.tenant_id', 't.id')->where('a.is_current', true))),
            'identity.usable_tenant_without_active_owner' => $count($usableTenants()
                ->whereNotExists(fn (Builder $q) => $q->from('tenant_memberships as m')->whereColumn('m.tenant_id', 't.id')->where('m.is_owner', true)->where('m.status', 'active'))),
            'api.live_credential_of_non_member' => $count(DB::table('api_credentials as c')->whereNull('c.revoked_at')
                ->where(fn (Builder $q) => $q->whereNull('c.expires_at')->orWhere('c.expires_at', '>', $now))
                ->whereNotExists(fn (Builder $q) => $q->from('tenant_memberships as m')->whereColumn('m.tenant_id', 'c.tenant_id')->whereColumn('m.user_id', 'c.user_id')->where('m.status', 'active'))),
            'integrations.delivery_sending_past_claim' => $count(DB::table('webhook_deliveries')->where('status', 'sending')->where('claimed_until', '<', $now)),
            'integrations.inbound_processing_past_claim' => $count(DB::table('inbound_webhook_events')->where('status', 'processing')->where('claimed_until', '<', $now)),
            'platform.purge_past_lease' => $count(DB::table('tenant_deletion_requests')->where('status', 'purging')->where('lease_until', '<', $now)),
            'platform.support_grant_active_past_expiry' => $count(DB::table('support_access_grants')->where('status', 'active')->where('expires_at', '<', $now)),
            'audit.not_append_only_in_database' => $auditProtected ? 0 : 1,
        ];

        return ['failures' => array_filter($failures), 'warnings' => array_filter($warnings)];
    }

    /**
     * @return array{checked: int, violations: array<string, int>}
     */
    private function foreignKeys(): array
    {
        $db = DB::connection();

        if ($db->getDriverName() === 'sqlite') {
            $rows = $db->select('PRAGMA foreign_key_check');
            $violations = [];

            foreach ($rows as $row) {
                $key = $row->table.' → '.$row->parent;
                $violations[$key] = ($violations[$key] ?? 0) + 1;
            }

            return ['checked' => count($db->select("SELECT name FROM sqlite_master WHERE type = 'table'")), 'violations' => $violations];
        }

        $keys = collect($db->select('SELECT CONSTRAINT_NAME AS name, TABLE_NAME AS child, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS parent, REFERENCED_COLUMN_NAME AS pcol FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'))
            ->groupBy(fn (object $key): string => $key->child.'.'.$key->name);
        $violations = [];

        foreach ($keys as $name => $columns) {
            $first = $columns->first();
            $notNull = $columns->map(fn (object $c): string => "c.`{$c->col}` IS NOT NULL")->implode(' AND ');
            $join = $columns->map(fn (object $c): string => "p.`{$c->pcol}` = c.`{$c->col}`")->implode(' AND ');
            $count = (int) $db->selectOne("SELECT COUNT(*) AS n FROM `{$first->child}` c WHERE {$notNull} AND NOT EXISTS (SELECT 1 FROM `{$first->parent}` p WHERE {$join})")->n;

            if ($count > 0) {
                $violations[(string) $name] = $count;
            }
        }

        return ['checked' => $keys->count(), 'violations' => $violations];
    }

    /**
     * @return list<string>
     */
    private function pendingMigrations(): array
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return ['(migrations table missing)'];
        }

        $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
        $ran = $migrator->getRepository()->getRan();

        return array_values(array_diff($files, $ran));
    }
}
