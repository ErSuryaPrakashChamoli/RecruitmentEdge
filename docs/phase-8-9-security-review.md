# Phase 8.9 Security Review: Security at Scale (Discovery)

**Status:** discovery only. Nothing was fixed and no security decision was made.

**Baseline:** `dce11d9` (Phase 8.8 freeze; application `05a9fd3`).

**Method:**
- code review with path:line evidence, each finding re-checked in code;
- `EXPLAIN` and benchmarks on throwaway databases only.

**Phase 8.8 dispositions are unchanged.** None is reclassified:
- SEC-88-02 stays **deferred** to the dedicated data-governance / retention phase;
- SEC-88-05, 07, 14 stay **accepted (B)**;
- SEC-88-10, 16, 18, 20–23, 25–28 stay **deferred (C)**.

## 1. Stop-condition check

| Condition | Result |
|---|---|
| A *newly discovered* critical active vulnerability | **None.** The most serious new finding, P89-SEC-001, is High: an authorization bypass by authenticated managers, audited and recoverable. |
| A critical production exposure already known before this phase | **Yes — carried forward, not new.** The production line (`main` @ `9cba8e3`) still lacks the Phase 8.6 SEC-1 delete-authorization fix, which 8.6 rated Critical. The hotfix `2fab3fd` is unmerged and undeployed, and itself lacks SEC-86-I-01. It is tracked as P86-BACKLOG-007 and in the Phase 8.8 freeze §11, and needs the release decision D8.9-027. |

Normal discovery therefore continued.

## 2. Findings

Severity is the impact at enterprise scale. "Verified" means the claim was re-checked in code during this phase.

| ID | Severity | Affected component | Evidence (verified) | Impact | Current control | Recommended direction | Implementation dependency | Decision dependency |
|---|---|---|---|---|---|---|---|---|
| **P89-SEC-001** | **High** | Recruitment daily targets (Filament resource) | `RecruitmentDailyTargetResource` has no `getEloquentQuery()` scope. The table offers `DeleteBulkAction` (`RecruitmentDailyTargetsTable.php:50-54`). `deleteAny` checks only `targets.configure` (`RecruitmentDailyTargetPolicy.php:61-64`), while per-record `delete` adds an `isInScope` check (`:39-56`). Filament 5's `DeleteBulkAction` deletes every selected record without per-record authorization unless `authorizeIndividualRecords()` is set (`vendor/filament/actions/src/DeleteBulkAction.php:80-104`; `Concerns/CanBeAuthorized.php:252-285`), and nothing in `app/` sets it. `manager` and `vp_hr` hold `targets.configure`. | A manager or VP HR sees **every** target in the organisation and can hard-delete them in bulk, outside their hierarchy. Targets feed performance and incentive tracking. | per-record `delete` policy, which is not used by the bulk action; the model is Auditable, so deletions are recorded | scope the resource query to the hierarchy and call `authorizeIndividualRecords()` on bulk deletes; check every other `DeleteBulkAction` for the same pattern (the others found are scoped or denied) | small code change + tests | D8.9-031 (Security) |
| **P89-SEC-002** | Medium | Staff email-change verification (Filament `VerifyEmailChange`) | `CredentialService.php:87-97` queues Filament's `VerifyEmailChange` (`ShouldQueue`, not encrypted, no `onQueue`). It is not rebound in `AppServiceProvider` (only `ResetPassword` and `NoticeOfEmailChangeRequest` were, in 8.7). | The signed verification URL sits in plaintext in `jobs.payload` (and `failed_jobs` on failure), on the `default` queue. | none for this notification | bind an encrypted subclass on a named queue, as 8.7 did for its siblings (ED-06) | small | Security sign-off |
| **P89-SEC-003** | Medium | Queue workers: authorization at execution time | Spatie keeps its permission map in process memory and never reloads it (`PermissionRegistrar.php:183-188`; `config/permission.php:128` Octane reset off). Workers live up to `--max-time=3600` (`docker-compose.yml:69,83,97`). Automation and AI jobs re-check permissions at run time (`AutomationEngine.php:476-498`; `RunsForRequester.php`). | Up to one hour after a role's permissions are edited, workers may act on the old permissions. Changes to a user's own roles are read fresh. Runtime impact is inferred; the code path is confirmed. | access state is re-read per job; permissions are not | clear the registrar per job (ED-11), or restart workers after role edits | small | Security |
| **P89-SEC-004** | Medium | Governance: last-CHRO protection | Concurrency review: `AuthorityGuard.php:59-68,116-131` counts other CHROs without a lock; each request locks only its own target; `RoleAssignmentService.php:72-75` takes no lock | Two concurrent suspensions, separations or role removals can leave **zero** CHROs (write skew) | single-request guard | serialise authority-removing actions on one lock and re-count inside it | small | Security |
| **P89-SEC-005** | Medium *(conditional on the build host)* | Docker image build | `.dockerignore` excludes `storage/framework/*` and `storage/logs/*` but **not `storage/app/*`**; `Dockerfile:39` `COPY . .` | An image built from a host with real files carries candidate documents and offer letters in its layers. This development host currently holds 231 offer-letter PDFs written by tests (DQ-88-16), plus smoke-test fixtures. A fresh `storage-data` volume would also be seeded from them. | none | exclude `storage/app` from the build context (ED-12) | trivial | Operations confirms how production images are built |
| **P89-SEC-006** | Medium *(conditional on the topology)* | `docker-compose.yml` database | `MYSQL_ALLOW_EMPTY_PASSWORD: "yes"` (`:114`); default `DB_PASSWORD` `secret` (`:38`, `:113`) | If this compose file is the production topology, MySQL root has no password inside the container network | network isolation only | Operations confirms; use secrets and a root password if compose is used in production | configuration | D8.9-026 (Operations) |
| **P89-SEC-007** | Low | Shared `sessions` table (side effect of D8.8-001) | `DatabaseSessionHandler::userId()` uses the default guard (`vendor/.../Session/DatabaseSessionHandler.php:218-221`). Portal requests switch it to `candidate` (`UseCandidateSessionContext.php:32-33`), so candidate sessions store candidate-account ids in `user_id`. Staff "sign out everywhere" deletes by `user_id` (`SessionRevocationService.php:39-44`). | A staff revocation can also end a candidate session that has the same numeric id (availability only, no access gained). Access Review session counts are inflated. Authentication isolation itself holds: separate cookies, guards, fingerprint and epoch. | separate cookies and guards | record the guard with the session, or filter revocation by guard | small | Security |
| **P89-SEC-008** | Low | Logs | Apache `CustomLog … combined` (`docker/apache/000-default.conf:10`) records full query strings, including the signatures of signed links (portal set-password, scheduling, `files.private`). The `monthly` and `emergency` log channels have no redaction tap (`config/logging.php`). Default level is `debug`. | Bearer-link secrets in access logs; unredacted logs if those channels are ever selected | redaction tap on every other channel | strip query strings from the access log or use a custom format; add the tap to all channels | configuration | Operations / Security |
| **P89-SEC-009** | Low | Integrations | Zoom server-to-server access token cached in plaintext in the database `cache` table for 50 minutes (`ZoomMeetingProvider.php:88`) | Readable by anyone with database read access during its lifetime | short lifetime | encrypt cached secrets | small | — |
| **P89-SEC-010** | Low | Rate limits at scale | Not throttled: `files/private` (`routes/web.php:32-34`), the calendar OAuth routes, the careers index and feed. The careers part is already SEC-88-21 / PF-88-10 (deferred). Every IP-keyed limiter collapses into one bucket behind a proxy (SEC-88-10, deferred on the stated topology). | Abuse or scraping at scale; `files.private` needs a valid user-bound signature, so the risk is load, not access | signatures; authentication | per-user limiter on `files.private`; re-open SEC-88-10 if the topology changes | small | D8.9-026 |
| **P89-SEC-011** | Low | Stale authorization at send time | `SendTimeGuard.php:65-82` re-checks consent and context, **not the sender's access** | A manual message queued by a staff member who is suspended before it sends still goes out | queue delay is short | product decision on whether a suspended sender's queued messages are cancelled | small | Product + Security |
| **P89-SEC-012** | Informational | Queued Filament export jobs | Export jobs on `default` carry a serialized query, column map and id lists, unencrypted (`PrepareCsvExport.php:22,43-50`). Row contents are not in the payload. | Metadata only | ids-only design elsewhere | move to a named queue (ED-06); consider encryption | small | — |

## 3. Data-integrity races with security or financial impact

Cross-reference: these are catalogued as **P89-DQ-001 – 013** in `phase-8-9-discovery.md`. The four High items are summarised here because of their financial impact.

| Finding | Verified evidence | Impact |
|---|---|---|
| P89-DQ-001 incentive payments and transitions unlocked | `IncentiveApprovalService` uses transactions but **no row lock** (no `lockForUpdate` in the file). No unique constraint limits payments per calculation. | double payment rows; double reversal adjustments |
| P89-DQ-002 incentive calculator not serialised | `RecruiterIncentiveCalculator.php:158-167,246-267,331-341` | stale recalculation can revert Approved; duplicate top-ups |
| P89-DQ-003 joining transitions unlocked | `CandidateJoiningService.php` has **no `lockForUpdate`**; `guardActive` checks an in-memory copy (`:214-219`) | an incentive for a non-hire; two incentive periods for one join |
| P89-DQ-004 offer terms after acceptance | `OfferService::releaseRevision` locks only the revision row, then re-reads the offer without a lock (`OfferService.php:286-304`) | revised terms written onto an accepted offer, with a new letter |

These are races between near-simultaneous actions by authorised staff, not external exploits. Severity High reflects financial integrity, not exposure.

## 4. Security at scale — areas reviewed with no new defect

| Area | Result |
|---|---|
| Metric cache leakage across users | **None.** The key includes a fingerprint of the viewer's visible employee set (`MetricService.php:51-64`; `MetricScope.php:271-276`); two viewers share an entry only when their visibility is identical. |
| Hierarchy leakage in dashboards and Action Center | Scoped through `visibleEmployeeIdsFor`; widgets are permission-gated (`AuthorizesWidget`) |
| IDOR under bulk AI operations | AI tools are scoped (`ScopesToHierarchy`), capped at 50 items, and need human approval; queued AI work re-checks the requester's access |
| Candidate / staff session isolation | Holds (separate cookies, guards, fingerprint, epoch); see the P89-SEC-007 side effect |
| Exports after Phase 8.8 | 10,000-row cap, audit and owner-only 24-hour download in place; file expiry deferred with SEC-88-02 |
| Queue payloads | Application jobs carry ids; listeners, notifications and mails are encrypted. Exceptions: P89-SEC-002 and SEC-012. |
| Concurrent authorization | `EnforceStaffAccess` runs on every panel request, including Livewire updates; `Gate::before` denies non-active logins. Exception: worker permission map (P89-SEC-003). |

## 5. Summary

| Severity | New Phase 8.9 findings |
|---|---|
| Critical | **0** |
| High | **1** (P89-SEC-001) |
| Medium | **5** (P89-SEC-002, 003, 004, 005, 006) |
| Low | **5** (P89-SEC-007 – 011) |
| Informational | **1** (P89-SEC-012) |

Carried forward, **not counted as new**: the production delete-authorization gap (Phase 8.6 SEC-1, Critical; P86-BACKLOG-007; D8.9-027). Also the four High data-integrity races, catalogued as P89-DQ.
