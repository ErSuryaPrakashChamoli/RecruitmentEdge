# Production Readiness — Code Closure: Findings

**For:** the project owner, the release owner, Engineering and Security.

**Date:** 2026-10-05. **Branch:** `feature/production-readiness`, based on the release candidate `54e551b`.

**Inputs:**
- `docs/production-readiness-discovery.md` (A1–A29, the stop conditions);
- `docs/production-readiness-final-report.md`;
- `docs/saas-7-discovery.md`, `docs/saas-7-security-review.md`, `docs/saas-7-production-readiness.md`;
- the code of the operations commands, health checks, CORS and database configuration, and the runbooks.

**Rule for this phase:** close only what can legitimately be completed inside the repository. Everything that needs infrastructure, production data, an external test or an owner's choice stays open, with its dependency named. **Production remains NO-GO.**

**Names used here:**
- **`ops:verify-integrity`** is the command the closure prompt calls `ops:integrity`. It existed before this phase and was extended, not duplicated.
- **Status values:**
  - **CLOSED** — done in the repository, with evidence;
  - **MITIGATED** — code reduces the risk; a decision or infrastructure step remains;
  - **PARTIAL** — part done; the rest is named;
  - **DEFERRED** — code-closable but not done in this phase, with the reason;
  - **OPEN** — not code-closable; waits for its dependency.

## 1. Findings

| ID | Finding | Can code close it? | Action | Owner / infrastructure dependency | Status |
|---|---|---|---|---|---|
| PRC-01 | Nine required operational runbooks were missing (closure prompt; discovery A29) | Yes (documentation) | Written in `docs/runbooks/`: `leaked-api-credential`, `compromised-integration`, `webhook-failure-spike`, `failed-migration`, `failed-deployment`, `tenant-purge-failure`, `security-incident`, `backup-restore-verification`, `app-key-and-secret-rotation`. Every page, command, audit action and number in them was checked against the code. `production-environment.md` and `incident-recovery.md` link them. **While checking:** `queue-operations.md` said `schedule:list` lists 20 tasks; it lists 27 (corrected) | The procedures still need the infrastructure they name (backup, secret source, monitoring, container runtime) before they can be executed | **CLOSED** (9 / 9) |
| PRC-02 | Discovery A29 also listed runbooks outside the prompt's nine: billing webhook failure (#9), tenant suspension (#13), tenant deletion (#14), suspicious cross-tenant activity (#20) | #13, #14, #20: yes (documentation). #9: not before a provider exists | **#13:** `tenant-suspension.md` (suspend, reactivate with paused work resumed, cancel; billing-driven suspension). **#14:** the deletion workflow is in `tenant-purge-failure.md` ("How a purge works": request, two-person approval, grace, cancel, purge) and `tenant-suspension.md` §3 (cancellation, its precondition). **#20:** `security-incident.md` §4 step 4 (verify, refused attempts in the log, legitimate cross-tenant paths, confirmed case). **#9:** not written | **#9:** D-S4-O1 (the payment provider; only the fake adapter exists, so there is no real billing webhook to fail) | **CLOSED** for #13, #14, #20; **#9 OPEN** (owner) |
| PRC-03 | A29 partial runbooks: secret rotation (#17), `APP_KEY` rotation (#18) | Yes | `app-key-and-secret-rotation.md`: the application procedure, what the key protects, downtime, workers, link lifetimes, rollback, other secrets by variable name. The infrastructure (secret-manager) procedure is marked as not existing | D-S7-O1 (secrets manager) | **CLOSED** (application procedure); infrastructure procedure **OPEN** |
| PRC-04 | A29 partial: application outage (#1), cache outage (#3), worker overload (#5) | Yes | Unchanged: `incident-recovery.md` §1–§4 and `queue-operations.md` §2 already cover them in part. Not in this phase's nine | Monitoring (D-S7-O6) for detection | **DEFERRED** (unchanged from discovery) |
| PRC-05 | `ops:verify-integrity` did not check entitlement, billing, platform or API state (A11) | Yes | Extended, read-only (counts only; no write, repair or delete). It also reports the database driver, version and session UTC offset. **Failures** (exit non-zero) — states the services never produce: a deleted tenant without a purged request; a purged request whose tenant is not deleted; deletion-pending without an open request; a closed request still open; a paid invoice with an amount due; a refund above its payment; an encrypted value the configured keys cannot read. **Warnings** ("look at"): a usable tenant without a plan or an active owner; a live API credential of a non-member; deliveries, inbound events or purges past their claim or lease; a support grant active past its expiry; the audit trail not append-only in the database. **Tests:** `tests/Feature/Hardening/PostRestoreIntegrityTest.php` (12), including a proof that the command issues no write statement | None for the check. Running it on a **restored production copy** needs the backup and production copy | **CLOSED** (code) |
| PRC-06 | Audit row integrity: no hash chain, only trigger presence (A11) | Only as a product change (a new tamper-evidence design); out of scope ("do not expand the product") | None. The check reports trigger presence | D-S7-O8 / D-S5-O10 (audit immutability model) | **OPEN** (owner) |
| PRC-07 | Preflight had one rule set: production blockers, otherwise nothing (A4) | Yes | **Three tiers by `APP_ENV`:** production (as before: blockers stop the container), staging (production-only items become warnings), development (everything is a warning). It prints which rules it applied (`rules` in `--json`). **New checks:** application time zone UTC; trusted hosts that leave out the `APP_URL` host; default database driver MySQL/MariaDB; private files not under `public/`; trusted proxies `*`; CORS any origin; database session time zone; unknown `APP_ENV`. **`PLATFORM_NOTIFY_EMAIL`** missing or invalid became a **production blocker** (it was a warning): with it unset, critical platform events reach nobody (stop condition 11). **Tests:** `tests/Feature/Hardening/OperationsTest.php` | Production values must come from the production secret source (D-S7-O1). Who receives alerts is D-S5-O8 / D-S7-O6 | **CLOSED** (code) |
| PRC-08 | PR-01: CORS on `/api/*` allows any origin | The configuration can be made explicit; the **allowed origins** cannot be chosen by code | `config/cors.php` published: `api/*` only, `supports_credentials` false. Origins come from `CORS_ALLOWED_ORIGINS` (unset → any origin, the previous default; empty → none). Preflight warns while any origin is allowed. **Test:** two configured origins — an allowed origin is echoed, another gets no header, and no credentials header is sent. **Analysis:** not a vulnerability for a bearer-only API (no cookie, no session on `/api`). No domain was invented | D-S6-O1 (API exposure domains) | **MITIGATED**, open (owner) |
| PRC-09 | PR-02: the database session time zone is not pinned; this server runs IST (+330 min) while the application uses UTC | Partly | **Support:** `DB_TIMEZONE` on the `mysql` connection (unset by default: no behaviour change). **Detection:** preflight `db_timezone` warning with the measured offset; `ops:verify-integrity` prints the offset. **The application zone** stays UTC (preflight blocks another zone); tenant display zones are untouched. **Not changed:** the session zone of existing data. The schema has 448 TIMESTAMP columns, which MySQL converts by session zone. Pinning `+00:00` on a database that was written in another zone changes how those values read. That must be measured on a production copy | Production-copy rehearsal (D-S7-O10); the production server's zone (DBA) | **MITIGATED**, open (**release gate**) |
| PRC-10 | PR-03: the two hotfix branches (`2fab3fd`, `599f0c5`) are not ancestors of the candidate | Yes, as evidence (history is not rewritten) | **Hotfix tests run against the candidate:** `599f0c5` contains `2fab3fd`, so its three test files cover both hotfixes (`DeleteAuthorizationTest`, `JoiningCreateAuthorizationTest`, `PolicyActionCoverageTest`). Taken from `599f0c5` and run on the candidate's code, they pass 6 / 6. The candidate already had the first (identical) and the third (adapted, 5 lines). **Missing test added:** the candidate lacked the joining-create test; it is now `tests/Feature/Security/JoiningCreateAuthorizationTest.php` (2 tests, pass). The fixes are present by content; nothing was cherry-picked, and no history was altered | Which code line production runs (`main`, a hotfix line, or this candidate) is an owner decision (decision register PRD-01) | **CLOSED** (code evidence); release line **OPEN** (owner) |
| PRC-11 | Tenant #1 gets no owner from the migration: the backfill adds `is_owner` but sets it for nobody, and `TENANT_ONE_*` has no owner setting | Detection and procedure: yes. **Who** the owner is: no | **Detection:** `ops:verify-integrity` warns `identity.usable_tenant_without_active_owner`. **Procedure:** `docs/production-release-checklist.md` (post-migration step: `tenants:owner <slug> <email> --operator= --reason=`; the new owner must be an active member holding CHRO) | The owner names tenant #1's owner (PRD-03) | **CLOSED** (detected and documented); choice **OPEN** (owner) |
| PRC-12 | Webhook failure counts on `/health/queue` do not turn the endpoint unhealthy, and nothing alerts on them | A threshold could be coded, but its values (and whether a customer's endpoint failing is a platform problem) are a monitoring decision | Documented in `webhook-failure-spike.md` (signals and manual triage) | D-S7-O6 (monitoring vendor and on-call); D-S6-O5 | **OPEN** (owner / infrastructure) |
| PRC-13 | Webhook replay has no cap and no bulk form; API credentials have no bulk revocation | Yes, as features, but not as a defect | Documented in the runbooks, with the existing containment (entitlement override per tenant) | D-S6-O3 / D-S6-O5 if limits are wanted | **OPEN** (Info; no change) |
| PRC-14 | Release documentation: no single pre-release, release and post-release checklist | Yes | `docs/production-release-checklist.md` | The checklist's gates need infrastructure and owner sign-offs | **CLOSED** (documentation) |
| PRC-15 | The production-readiness matrix did not separate code from infrastructure from owner blockers | Yes | `docs/production-readiness-final-report.md` updated. Every blocker is classified (CODE / INFRASTRUCTURE / OWNER / EXTERNAL TEST / PRODUCTION DATA / RELEASE GATE) with its evidence; none removed | — | **CLOSED** (documentation) |
| PRC-16 | S7-15: forgot-password does slightly more work for a known address (S2-A4) | Yes, in principle (equalise the work) | Not changed. It is outside this phase's scope, and the response is already identical | None | **DEFERRED** (Low, carried forward) |
| PRC-17 | S7-12: audit triggers not installed | The command exists (`audit:protect`); installing it needs a privileged database user in production | Preflight warns; integrity warns; checklist step | D-S7-O8 (triggers vs application-only immutability); a privileged DB user | **OPEN** (owner / infrastructure) |
| PRC-18 | S7-13: export files kept forever | Pruning code needs a retention value | None | D-S7-O9 / D-S5-O6 | **OPEN** (owner) |
| PRC-19 | S7-16: `HTTPS_PROXY` would bypass DNS pinning; LibreOffice not network-isolated | No (network) | Documented (`production-environment.md`) | D-S7-O2, D-S7-O5 | **OPEN** (infrastructure) |
| PRC-20 | Production configuration unknown (A4); secrets (A5); egress (A6); domain and TLS (A7); database server (A8) | No | Preflight checks what code can see | D-S7-O1/O2/O3, D-S7-O13, DBA | **OPEN** (infrastructure) |
| PRC-21 | Production migration state unknown; 25 forward-only migrations in the delta (A9) | No | `failed-migration.md` documents the forward-only handling (restore, never `migrate:rollback`) | Production copy; D-S7-O10 | **OPEN** (production data / release gate) |
| PRC-22 | No backup system; no restore test (A10) | No | `backup-restore-verification.md` (a procedure awaiting infrastructure) | D-S7-O7 | **OPEN** (infrastructure) |
| PRC-23 | No monitoring, alerting or on-call (A16, A17) | No | Preflight now blocks a production start without an alert recipient (PRC-07) | D-S7-O6, D-S5-O8 | **OPEN** (infrastructure / owner) |
| PRC-24 | No CI (A19); container never built or run (A20) | No (a definition cannot be proven without a runner, and pushing is not allowed) | None | D-S7-O12 | **OPEN** (infrastructure) |
| PRC-25 | Billing: no production provider; prices, GST, numbering, finance operators (A24) | No | None | D-S4-O1…O11 | **OPEN** (owner) |
| PRC-26 | Platform policies (A25); API and integration decisions (A26) | No | None | D-S5-O1…O13; D-S6-O1…O15 | **OPEN** (owner) |
| PRC-27 | Load test; external penetration test (A23, §30–31) | No | None | Staging infrastructure; an external tester | **OPEN** (external test) |

## 2. Summary

| | Count | IDs |
|---|---|---|
| Code-closable, **closed** | 9 | PRC-01, PRC-02 (#13, #14, #20), PRC-03 (application procedure), PRC-05, PRC-07, PRC-10 (code evidence), PRC-11 (detection and procedure), PRC-14, PRC-15 |
| Code-closable, **mitigated** pending a decision or rehearsal | 2 | PRC-08 (PR-01), PRC-09 (PR-02) |
| Code-closable, **deferred** | 2 | PRC-04 (outage runbooks, partial since discovery), PRC-16 (S7-15) |
| Not code-closable — infrastructure, owner, production data, external test | 15 | PRC-02 #9, PRC-06, PRC-12, PRC-13, PRC-17 … PRC-27 |

**Runbooks:**
- **The nine the closure prompt named:** all written (9 / 9).
- **One more:** `tenant-suspension.md`, which closes discovery A29 #13. A29 #14 and #20 are closed inside existing runbooks.
- **Not written:** A29 #9 (billing webhook failure), until a payment provider exists.

**What this phase does not change:**
- Every stop condition met in discovery is still met.
- **Production stays NO-GO.**

## 3. Code and test changes

| File | Change |
|---|---|
| `app/Services/Operations/IntegrityVerifier.php` | SaaS-state failures and warnings, encryption readability, database facts (PRC-05) |
| `app/Services/Operations/DatabaseClock.php` (new) | Reads the database session's offset from UTC (MySQL; `null` elsewhere) |
| `app/Console/Commands/OpsVerifyIntegrity.php` | Prints the new sections |
| `app/Services/Operations/ProductionPreflight.php` | Tiers and new checks (PRC-07) |
| `app/Console/Commands/OpsPreflight.php` | Prints the tier; `rules` in JSON and in the log line |
| `config/cors.php` (new) | Explicit CORS for `api/*`, origins from `CORS_ALLOWED_ORIGINS` (PRC-08) |
| `config/database.php` | `timezone` from `DB_TIMEZONE` on the `mysql` connection (PRC-09) |
| `.env.example` | `DB_TIMEZONE`, `CORS_ALLOWED_ORIGINS` (commented), `PLATFORM_NOTIFY_EMAIL` note |
| `tests/Feature/Hardening/PostRestoreIntegrityTest.php` (new) | 12 tests |
| `tests/Feature/Hardening/OperationsTest.php` | Tier, new checks, time zone, CORS (HTTP behaviour and how `CORS_ALLOWED_ORIGINS` is read) |
| `tests/Feature/Security/JoiningCreateAuthorizationTest.php` (new) | PR-03 regression test from hotfix `599f0c5` |
| `tests/Unit/Commercial/CommercialArchitectureTest.php` | `IntegrityVerifier` allow-listed: it counts plan assignments for a warning (metadata, never a gate), like `PlatformDirectory` |
| `.ai/rules/operations.md` (new, via `record-rule`) | The integrity check stays read-only (failures vs warnings); preflight checks declare a scope and the tier sets the level |

Test results are in `docs/production-readiness-code-closure-final.md`.
