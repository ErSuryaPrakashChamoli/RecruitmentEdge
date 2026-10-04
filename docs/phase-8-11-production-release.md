# Phase 8.11 — Production Release & Stabilisation: Release Record

**For:** the release owner, Operations and Security.

**Status: BLOCKED. Nothing has been built, backed up or deployed.** The application is frozen at release candidate `226bc7d`.
- The steps that need production access, owner decisions or a container runner cannot run from this environment.
- No production value has been invented. Each missing input is marked **OWNER ACTION REQUIRED**.

| | |
|---|---|
| Application release candidate | `226bc7d` (`feature/sep_25_hrm`), verified clean on 2026-10-04 |
| Production line today | `9cba8e3` (`main` / `production`) |
| Image built | **No.** No container runner: `docker`, `podman`, `buildah`, `nerdctl`, `kaniko` and `img` are absent, and the repository has no CI configuration. |
| Production backup | **None taken** (no production access) |
| Deployed / pushed / merged | **No / No / No** |

## 1. Pre-release owner gates

| # | Gate | Status | What is needed |
|---|---|---|---|
| 1 | D8.10-005 / OP-01: image build | **BLOCKED (environment)** | A Docker-capable runner (`phase-8-10-release-readiness.md` §7). Build `226bc7d`, record the tag and digest, run the suite in the image, boot the compose topology. |
| 2 | D8.10-002 / 003 / 021: release strategy | **OWNER ACTION REQUIRED** | Approve §2 (recommendation: deploy the release candidate). Decide the downtime window, and whether to accept the development-host rehearsal or provide a production copy (OP-02). |
| 3 | D8.9-026 / D8.10-023: production facts | **OWNER ACTION REQUIRED** | `APP_URL`; `APP_TRUSTED_HOSTS` (include `localhost`); Apache `ServerName` and a default reject vhost; proxy and TLS termination (SEC-88-10); calendar and video redirect URIs (§3: kept off). |
| 4 | D8.9-007…010, 028: backup policy | **OWNER ACTION REQUIRED** | RTO, RPO (binlog / PITR), frequency, retention, off-host storage, key custody, DR, restore cadence |
| 5 | OP-14: secrets | **OWNER ACTION REQUIRED** | `APP_KEY` (existing, never regenerated), `DB_PASSWORD`, `QUEUE_HEALTH_TOKEN`, mail transport credentials, from the secret store (`docs/runbooks/production-environment.md`) |
| 6 | OP-05: capacity | **OWNER ACTION REQUIRED** | Apache workers versus MySQL `max_connections`; container memory limits |
| 7 | OP-06: binlog | **OWNER ACTION REQUIRED** | Keep with expiry, or disable (ties to the RPO) |
| 8 | OP-07: log rotation | **OWNER ACTION REQUIRED** | Container log driver limits; `LOG_DAILY_DAYS` (retention decision R-13) |
| 9 | OP-09: image registry | **OWNER ACTION REQUIRED** | Where release images are pushed and kept for rollback by tag |
| 10 | PM-01 / D8.10-006: interview time zone | **OWNER ACTION REQUIRED**; interim safety configuration in §3 | The product decision on the time-zone model |
| 11 | AI-15 / D8.10-014: AI provider | **OWNER ACTION REQUIRED**; **AI production enablement = DISABLED** (§3) | Provider data-handling terms and model choice |

## 2. Release strategy (for owner approval)

**Recommended: A, deploy the frozen release candidate `226bc7d`.** It closes the production Critical and the branch-fixed Highs in one release. Release A becomes unnecessary.

**Does `226bc7d` contain Release A's protection? Yes, verified on 2026-10-04:**
- **Files:** all 24 policy and trait files of Release A exist in `226bc7d`.
- **Abilities:** of the 40 policy abilities Release A adds, 37 exist identically. `ForbidsDeletion` is identical.
- **The other 3:** `EmployeePolicy::deleteAny`, `forceDeleteAny` and `restoreAny` are denied in `226bc7d` by the fail-closed `Gate::before` (`policyLacksAbility`). They return `false` even for the CHRO, and the Employees resource offers no bulk delete or restore.
- **Release A's own tests, run against `226bc7d`:** `JoiningCreateAuthorizationTest` 2 / 2 and `DeleteAuthorizationTest` 3 / 3 pass. The release candidate's suite also includes `PolicyCoverageTest`, `PolicyActionCoverageTest` and `StrictAuthorizationTest`.

| Release | Option A: release candidate (recommended) | Option B: Release A hotfix only |
|---|---|---|
| Commits | `9cba8e3..226bc7d`: 179 commits, 1,510 files | `9cba8e3` + `2fab3fd` + `599f0c5`: 26 files, +454 / −0 |
| Migrations | 75 → 164: **89 new**, 0 modified or deleted. They include 6 backfills and 2 unique-index drops; `grant_phase_*` permission grants are not reversible by `down()`. | None |
| Dependencies (runtime) | Same 125 packages. `laravel/framework` v13.29.0 → v13.30.1; `league/commonmark` 2.10.0 → 2.10.3 (security, D8.10-020). Image PHP 8.5.11 pinned by digest. | None (keeps the old versions: SEC-015 stays open) |
| Routes | `routes/web.php`, `routes/portal.php` and `routes/console.php` change: Phases 4–8.9 portal, careers, webhooks, health, files, calendar OAuth; 239 routes | None |
| Authorization | Fail-closed gate plus strict policies; every Release A rule; SEC-001, 002, 004, 006, 008; AI-01, 03, 11; DI-01, 02, 04 | Explicit delete, restore and force-delete rules; `CandidateJoiningPolicy::create` |
| Build | The release-candidate Dockerfile (PHP 8.5.11). Needs a runner. | **Cannot build from its own Dockerfile** (PHP 8.3 versus a lock needing ≥ 8.4.1, D8.10-021). Needs a runner too. |
| Rollback | **Restore the pre-release backup** (database and files) and redeploy the previous image tag. Migration `down()` is **not** a data rollback (P810-A4-01). | Redeploy the previous image; no data change |

**Choose B only** if A cannot be deployed soon and the Critical must be closed sooner. B still needs a container build and leaves SEC-001, SEC-004, SEC-015 and DI-01/02/04 open in production.

**Release owner approval:** ☐ Option A ☐ Option B. Name, date: **OWNER ACTION REQUIRED**.

## 3. Release configuration (no code change)

| Setting | Value at release | Why |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | — |
| `APP_URL` | **OWNER ACTION REQUIRED** (exact public origin) | Signed and emailed links use it (SEC-001) |
| `APP_TRUSTED_HOSTS` | **OWNER ACTION REQUIRED** (include `localhost`) | Optional host allow-list |
| AI | `GEMINI_API_KEY`, `OPENAI_API_KEY` and `AI_API_KEY` **unset**; `AI_ACTIONS_ENABLED=false`; `AI_WEB_SEARCH_ENABLED=false` | **AI disabled** until D8.10-014. The Copilot degrades to "not configured"; nothing else is affected. |
| Calendar and video sync | `GOOGLE_CALENDAR_*`, `MICROSOFT_GRAPH_*` and `ZOOM_*` **unset** | PM-01: no calendar or Zoom sync |
| Self-booking | **After migration**, a CHRO removes `interview-slots.manage` from vp_hr, manager, assistant_manager and recruiter (Roles screen; audited). The `grant_phase_four_permissions` migration grants it during `migrate`; the seeder is not run on deploy. CHRO users do not send self-booking invitations. | PM-01: a self-booked confirmation prints the stored UTC time |
| Interview scheduling | Manual only | PM-01 |
| Queues and cache | `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `DB_QUEUE_RETRY_AFTER=330`, `QUEUE_EXPECT_PROCESSES=true` | Shipped topology |
| Other | `docs/runbooks/production-environment.md` | — |

## 4. Deployment order

This is the established sequence (`docs/runbooks/queue-operations.md` §1), applied with the approved image tag:

1. Maintenance: `php artisan down --retry=60`.
2. Stop the scheduler.
3. Drain the workers: `php artisan queue:drain-status --wait=1800`.
4. Verify drained (exit 0).
5. Stop the workers and verify they have stopped.
6. Take the production backup and **verify it** (`backup-restore.md` §2, including step 5). Record the timestamp, database identity, checksums, encryption and storage location.
7. `APP_IMAGE_TAG=<approved> docker compose up -d`. The one-shot `migrate` runs first.
8. Verify the migration result: `migrate:status` (no pending, 164 ran); `lifecycle:audit` (report only).
9. Start the workers. Compose starts them once the app is healthy.
10. Start the scheduler.
11. Caches rebuild on container start.
12. Health: `GET /up` 200; `GET /health/queue` with the token.
13. Apply the §3 role change (self-booking off).
14. `php artisan up`.
15. Production smoke tests (§5).

**Rules:** no old worker runs during migration; no data cleanup; no historical remediation.

## 5. Production smoke-test plan (after deployment)

Smoke records in production are created and labelled `SMOKE-8.11 — do not use`. They are closed through their lifecycle actions afterwards, never deleted (no data cleanup during the release).

**OWNER ACTION REQUIRED:** approve smoke records in production, or name a test organisation unit.

| Area | Check |
|---|---|
| Authentication | Staff login (with MFA where enforced), logout, password reset email; the link host equals `APP_URL` |
| Requisition | Create; another approver approves; visible to the owning team only |
| Candidate | Create, search, team-scoped visibility |
| Application | Create, recruiter assignment, stage move |
| Interview | Schedule manually; feedback by the assigned interviewer |
| Offer | Create, release (`offers.release`), record acceptance; the joining appears |
| Joining | Mark Joined; one incentive calculation where a rule applies |
| Employee | Convert once |
| Outcome | Joined outcome recorded (after `outcomes:evaluate`) |
| AI | Copilot shows "not configured" with no error (AI disabled) |
| Queue | A representative job (for example the password-reset mail) processed; 0 failed |
| Notifications | A representative in-app notification |
| Health | `/up` 200; `/health/queue` healthy |
| Portal | Candidate set-password link, sign-in, dashboard |
| Audit | The audit log shows the actor on the lifecycle actions above |

## 6. Monitoring available (no new tooling)

| Signal | Source |
|---|---|
| Liveness | `GET /up` (external poll, D8.9-020) |
| Queue health | `GET /health/queue` (bearer token); `queue:health-check` (scheduled, in-app platform alerts) |
| Workers and scheduler | Heartbeats (compose health checks); `queue:drain-status` |
| Failed jobs | Queue health page; `failed_jobs` (redacted) |
| Application errors | Daily log files (`LOG_STACK=daily`), redacted, with request ids |
| Authentication failures | Audit log (login, lockout, MFA) |
| Lifecycle consistency | `lifecycle:audit`, `storage:audit` (report only) |
| Notifications | Communication status (blocked, failed); provider circuit breaker |

## 7. Stabilisation and rollback

**Fix only:** production-blocking defects, corruption, authorization or security regressions, a broken core workflow, deploy, queue or scheduler failures, critical errors. Everything else goes to `post-rms-backlog.md`.

**Rollback:**
1. Stop the rollout.
2. Maintenance on.
3. Stop the scheduler and workers.
4. **Restore the verified pre-release backup** (`backup-restore.md` §3).
5. Redeploy the previous image tag.
6. Verify.
7. Record the incident, cause, release, rollback decision, backup used and result (`incident-recovery.md` §5).

## 8. Exact blockers (2026-10-04)

1. **Image build:** no container runner in this environment and no CI (D8.10-005). The image has never been built, so it has no tag or digest.
2. **No verified production backup:** no production access, and the backup policy is undecided (D8.9-007…010, 028). **PRODUCTION RELEASE BLOCKED — NO VERIFIED PRODUCTION BACKUP.**
3. **Release strategy not approved** (D8.10-002 / 003 / 021).
4. **Production facts missing** (D8.9-026, D8.10-023): `APP_URL`, hosts, proxy and TLS, secrets, capacity, binlog, log rotation, registry.

Every deployment, smoke-test and stabilisation step depends on 1–4. None was performed.

**RMS COMPLETE is not declared.**
