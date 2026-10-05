# Production Readiness — Security Review (Code Closure)

**For:** Security, the project owner and the release owner.

**Date:** 2026-10-05. **Scope:**
- the open findings carried into production readiness (SaaS-7 review §6, discovery A22);
- the three findings of the production-readiness discovery (PR-01, PR-02, PR-03);
- the code changed in this phase;
- the findings recorded while closing.

**Not in scope:** an external penetration test. It is still **PENDING** and still a GA blocker (discovery A23).

**Rule:** no finding is closed without evidence. S7-12, S7-13, S7-15, S7-16, PR-01, PR-02 and PR-03 are each reviewed below, with their status.

## 1. Counts

| Severity | Open before this phase | Open after | Change |
|---|---|---|---|
| Critical | 0 | **0** | — |
| High | 0 | **0** | — |
| Medium | 1 (S7-12) | **1** (S7-12) | unchanged; mitigations added |
| Low | 5 (S7-13, S7-15, S7-16, PR-02, PR-03) | **4** (S7-13, S7-15, S7-16, PR-02) | PR-03 **closed with evidence**; PR-02 mitigated, still open |
| Info | 3 (S7-14, S7-25, PR-01) | **6** (S7-14, S7-25, PR-01, PR-04, PR-05, PR-06) | PR-01 mitigated, still open; PR-04…06 recorded in this phase |

**Dependency audits** (run in this phase, 2026-10-05): `composer audit` — no advisories; `npm audit` — 0 vulnerabilities.

## 2. Findings carried in

### S7-12 — Audit immutability is application-only (Medium) — **OPEN**

- **Mitigated by code since SaaS-7:**
  - the `audit:protect` triggers make `audit_logs` append-only in the database;
  - the purge retains `audit_logs`, so the two do not conflict.
- **Still open:** the triggers are installed nowhere. Installing them needs a privileged database user in production (binary logging refuses `CREATE TRIGGER` to the application user). The application user's UPDATE/DELETE grant is not revoked by the command.
- **This phase:**
  - `ops:verify-integrity` warns `audit.not_append_only_in_database`;
  - preflight warns `audit_protection`;
  - the release checklist makes it an owner decision with an install step.
- **Closes when:** the triggers are installed and `audit:protect status` shows them, **or** the owner records acceptance of application-only immutability (D-S7-O8; decision register PRD-05).

### S7-13 — Export files kept forever (Low) — **OPEN**

Nothing prunes compliance or data export files. Pruning code needs a retention value, which is an owner decision (D-S7-O9, D-S5-O6). Exports stay on the private disk; they are downloaded only through the panel and are audited. No change in this phase.

### S7-15 — Forgot-password timing difference (Low) — **OPEN** (carried forward)

- **The finding** (S2-A4): for a known address the request does slightly more database work (the panel check) than for an unknown one. The response is identical, and the mail is queued.
- **Code-closable:** yes, by equalising the work.
- **Status:** not changed in this phase; out of the closure scope. **Deferred**, not closed.

### S7-16 — `HTTPS_PROXY` would bypass DNS pinning; LibreOffice not network-isolated (Low) — **OPEN**

Infrastructure. The application's SSRF guard is verified, and the production checklist forbids `HTTPS_PROXY` for webhook delivery. Closing it needs:
- network egress controls (D-S7-O2);
- an isolated conversion worker (D-S7-O5).

### S7-14 — Upload fields without an explicit size (Info) — **OPEN** (accepted)

Bounded at 12 MB globally. Unchanged.

### S7-25 — Security headers not on the panels (Info) — **OPEN**

Approved scope E-03. Kept for the external penetration test.

## 3. Production-readiness findings

### PR-01 — CORS on `/api/*` allows any origin (Info) — **MITIGATED, OPEN** (owner)

**Analysis.** The API authenticates **only** by a bearer token in the `Authorization` header:
- no cookie;
- no session on `/api`;
- `supports_credentials` false.

A browser on another origin cannot make the API act with a victim's ambient credentials, because there are none to borrow. "Any origin" lets a page holding a token call the API from a browser; it does not expose a user. **This is not a vulnerability.** It is an exposure choice: should integrators call the API from browsers?

**Change** (`config/cors.php`, published):
- **Scope:** paths `api/*` only. The panels and the portal are untouched.
- **Credentials:** `supports_credentials` false.
- **Origins:** from `CORS_ALLOWED_ORIGINS` (comma-separated).
  - **Unset:** any origin — the previous default, unchanged.
  - **Empty:** no origin.
- **Preflight:** warns `cors_any_origin` while any origin is allowed.
- **No domain** was invented.

**Evidence:** `OperationsTest` — with two configured origins, an allowed origin is echoed, another origin gets no `Access-Control-Allow-Origin`, and no `Access-Control-Allow-Credentials` is sent.

**Closes when:** the owner decides the API exposure domains (D-S6-O1) and the variable is set accordingly, or the owner records that any origin is accepted.

### PR-02 — Database session time zone not pinned (Low) — **MITIGATED, OPEN** (release gate)

**Analysis.**
- **Application:** stores and compares every time in UTC (`app.timezone` UTC, hard-coded). Tenants display in their own zone.
- **Database session:** uses the server's zone (IST, +330 min, on this host). Database-side `NOW()` / `CURRENT_TIMESTAMP` defaults and TIMESTAMP conversion therefore differ from application time.
- **Why it cannot simply be switched:** the schema has 448 TIMESTAMP, 6 DATETIME and 37 DATE columns. MySQL converts TIMESTAMP values by session zone, so pinning `+00:00` on a database written under another zone changes how existing values read.

**Change:**
- **Support:** `DB_TIMEZONE` on the `mysql` connection, unset by default, so behaviour is unchanged.
- **Detection:**
  - preflight warns `db_timezone` with the measured offset (and blocks any application zone other than UTC, `app_timezone`);
  - `ops:verify-integrity` prints the offset.
- **Not changed:** tenant display zones.

**Evidence:** `OperationsTest` with a fake `DatabaseClock` (330 → warning; 0 → none) and the application-zone blocker case.

**Closes when:** the production-copy rehearsal shows how existing TIMESTAMP data reads under `+00:00`, the setting is decided (decision register PRD-04), and production runs at offset 0 or with the recorded decision.

### PR-03 — Hotfix branches not ancestors of the candidate (Low) — **CLOSED** (with evidence)

**The finding.** `hotfix/filament-delete-authorization` (`2fab3fd`) and `hotfix/p810-production-authorization` (`599f0c5`, which contains `2fab3fd`) are not ancestors of the candidate. Their fixes were believed present by content.

**Evidence:**
- `599f0c5`'s three test files (`DeleteAuthorizationTest`, `JoiningCreateAuthorizationTest`, `PolicyActionCoverageTest`) were run against the candidate's code: **6 / 6 pass**. The fixes are present and equivalent; none is missing.
- The candidate lacked `JoiningCreateAuthorizationTest`. It is added (`tests/Feature/Security/JoiningCreateAuthorizationTest.php`, 2 tests, pass, with a provenance note), so the joining-create rule cannot regress silently.
- History was not altered and nothing was cherry-picked.

**What remains is not a security defect.** Which line production runs today, and which it will run, is an owner decision (decision register PRD-01) and a release-checklist gate (§1). A production line that lacks these fixes is the risk, and the checklist names it.

## 4. Findings recorded in this phase

| ID | Finding | Severity | Status |
|---|---|---|---|
| PR-04 | Tenant #1 has no owner after the migration (the backfill adds `is_owner` but sets nobody) | Info | **Mitigated:** `ops:verify-integrity` warns `identity.usable_tenant_without_active_owner`; the release checklist assigns the owner with `tenants:owner` (audited `ownership_assigned`). Open until the owner names one (PRD-03) |
| PR-05 | Webhook failure counts on `/health/queue` do not make it unhealthy, and nothing alerts on them; a failing or compromised integration is noticed by hand | Info (detection) | **Open:** monitoring (D-S7-O6) and thresholds (D-S6-O5). Signals and triage documented in `webhook-failure-spike.md` |
| PR-06 | No bulk credential revocation; webhook replay has no cap | Info | **Open, accepted as documented:** containment per tenant exists (`tenants:entitlement … api.access off`, `integrations.webhooks off`); replay is audited and goes through the budget and circuit. Limits would be D-S6-O3 / D-S6-O5 |

## 5. Review of the code changed in this phase

| Change | Security review |
|---|---|
| `IntegrityVerifier` (SaaS state, encryption, database facts) | **Read-only, proven:** a test captures every statement while the command runs, and none is an insert, update, delete, replace, truncate, alter, drop or create. **Counts only;** no row content is printed. **Encryption:** values are decrypted in memory (`security:reencrypt --dry-run` path) and never output. **No repair, deletion, tenant, entitlement, billing or platform change.** Platform-level reads across tenants are by nature (counts) |
| `ProductionPreflight` tiers and checks | Production is never weakened: every check that blocked production still blocks it. Staging blocks the same deploy-wide items. Development only warns. **Messages name settings, never values:** they interpolate only the cipher, driver and connection names, and the time-zone offset. **New production blockers:** an application zone other than UTC, a non-MySQL default driver, private files under `public/`, trusted hosts that leave out the `APP_URL` host, no valid alert recipient |
| `config/cors.php` | Scope `api/*` only; credentials off; the default is unchanged (PR-01) |
| `DB_TIMEZONE` | Unset by default: no behaviour change (PR-02) |
| `DatabaseClock` | One read-only query (`timestampdiff(minute, utc_timestamp(), now())`) on MySQL; `null` elsewhere |
| Runbooks | **Bypass check:** no runbook offers a way around authorization. Purge retries need `platform.deletion.manage` and a named operator, and the two-person approval stays. Containment uses the existing audited commands and actions. **No secrets:** tokens are referred to by key id, secrets by variable name. **Notification** is marked an owner / security / legal decision everywhere; no legal duty is invented |

**Silent findings:** none. Every open item above has a disposition and a dependency.
