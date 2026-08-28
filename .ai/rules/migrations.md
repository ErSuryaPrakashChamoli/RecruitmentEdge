---
paths:
  - 'database/migrations/**'
---

# Migrations

## Always name multi-column indexes/uniques explicitly
MySQL's 64-char identifier limit is hit repeatedly by Laravel's auto-generated composite index/unique names on this project's longer table names (e.g. `recruitment_daily_targets`, `recruiter_performance_snapshots`). Always pass an explicit short name as the second argument to `$table->unique([...], 'short_name')` / `$table->index([...], 'short_name')` for any composite index on a table with a long name — don't rely on Laravel's auto-generated name. When a migration fails, read the FULL error text (don't pipe through `tail`/`head` on a first look) — a truncated error can look like a totally different failure (e.g. "table already exists" on retry) and send you chasing the wrong cause, since the failed migration's DDL up to the failure point still committed (MySQL DDL isn't transactional) and left a partial table behind.
