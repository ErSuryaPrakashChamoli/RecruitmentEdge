---
paths:
  - 'database/migrations/**'
---

# Migrations

## Always name multi-column indexes/uniques explicitly
MySQL's 64-char identifier limit is hit repeatedly by Laravel's auto-generated composite index/unique names on this project's longer table names (e.g. `recruitment_daily_targets`, `recruiter_performance_snapshots`). Always pass an explicit short name as the second argument to `$table->unique([...], 'short_name')` / `$table->index([...], 'short_name')` for any composite index on a table with a long name — don't rely on Laravel's auto-generated name. When a migration fails, read the FULL error text (don't pipe through `tail`/`head` on a first look) — a truncated error can look like a totally different failure (e.g. "table already exists" on retry) and send you chasing the wrong cause, since the failed migration's DDL up to the failure point still committed (MySQL DDL isn't transactional) and left a partial table behind.

## Foreign keys also exceed MySQL's 64-char name limit — name them explicitly
Laravel's auto-generated foreign key names (table_column_foreign) break MySQL's 64-character limit just like composite indexes, e.g. recruitment_pipeline_template_stages_recruitment_stage_id_foreign. On long table names, use $table->foreignId('x'); $table->foreign('x', 'short_name')->references(...) instead of ->constrained(). Tests run on SQLite, which has no such limit, so only a MySQL migrate catches this. If it fails part-way, drop the partial table before retrying.

## New tenant-owned tables: tenant_id NOT NULL, composite keys, frozen lists
SaaS-1: a new tenant-owned table needs tenant_id (unsignedBigInteger NOT NULL, FK to tenants, restrictOnDelete), its business keys unique per tenant (tenant_id first), and CASCADE/RESTRICT references as composite FKs (tenant_id, x_id) → parent (tenant_id, id) — the parent needs unique (tenant_id, id). ON DELETE SET NULL references cannot be composite in MySQL: list them in TenantSchema::REFERENCES (checked in-app and by tenancy:verify). Migrations embed frozen copies of table lists, never read App\Services\Tenancy\TenantSchema. Data rollback is a backup restore, never down().

## Foreign keys must not block or cascade into the tenant purge
SaaS-5: TenantPurgePlan derives the purge from the schema and refuses (deleting nothing) when a table outside the purge — retained (config platform.deletion.retain_tables), platform or global — references a purged tenant table with RESTRICT/CASCADE. A new FK from such a table to a tenant table must be nullOnDelete(). A tenant-derived table without tenant_id that cascades with its parent must be added to TenantPurgePlan::CASCADED_WITH_PARENT. A new tenant table is purged and exported by construction once TenantSchema classifies it.

## Pre-SaaS migrations: query builder only, never spatie Role/Permission models
Client #1 (2026-10-08): a database on the 75-migration line (9cba8e3) runs every migration up to 2026_10_04_025113 with roles that have no tenant_id/key, while config permission.teams is true today. Spatie's Role::findOrCreate/findByName/findById/create add `tenant_id is null or tenant_id = ?` and fail with 1054 on MySQL. A migration that reads or writes roles/permissions before SaaS-1 must use DB::table() against the schema at that point (see 2026_09_25_135221, 2026_10_04_144459), frozen constants, insert-if-missing, never sync. SQLite hides this bug (unknown double-quoted column = string literal; Eloquent drops non-column attributes): prove upgrade paths on MySQL — tests/Feature/Tenancy/LegacyReleaseUpgradeTest runs on the suite's driver. 2026_09_27_075958 (Phase 8.5) also needs offers.manage and performance.view to exist whenever a role exists.
