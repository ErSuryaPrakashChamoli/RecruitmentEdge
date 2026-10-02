# Phase 8.9 Security Review: Security at Scale (Discovery)

**Status:** discovery (§1–§5), then **re-reviewed after the approved implementation (§6)**. The discovery text is kept unchanged as the record of what was found.

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

## 6. Implementation re-review (Phase 8.9 implementation)

**Scope.** The approved implementation on `feature/sep_25_hrm`, from `dce11d9` to the final application commit named in `phase-8-9-implementation.md`. Every finding was re-checked in the new code. Each disposition below names its evidence: the commit, the test, and the browser check where one exists.

**Unchanged.** The Phase 8.8 dispositions stay as they were:
- SEC-88-02 is deferred to the data-governance / retention phase;
- SEC-88-05, 07 and 14 are accepted (B);
- SEC-88-10, 16, 18, 20–23 and 25–28 are deferred (C).

No audit row is deleted and no retention behaviour changed.

### 6.1 Dispositions

| ID | Sev | Disposition | What changed | Evidence | Residual |
|---|---|---|---|---|---|
| **P89-SEC-001** | **High** | **FIXED** | Targets are scoped to the viewer's hierarchy (`RecruitmentDailyTarget::visibleTo` / `isVisibleTo`, used by the resource query and the policy). Every write goes through `RecruitmentTargetService` (Gate plus scope check per record). The bulk delete authorises each record (`authorizeIndividualRecords('delete')`) and deletes through the service. The form offers only visible employees; department / designation-level targets need `hierarchy.view-all`. | `d939533`; `tests/Feature/Security/SEC8901TargetScopeTest.php`; browser `p89` checks 15–16 | none |
| P89-SEC-002 | Medium | **FIXED** | `App\Notifications\Auth\VerifyEmailChange` is bound in place of Filament's. It is encrypted and runs on the `security` queue. | `4607de3`; `CredentialLifecycleTest`, `QueuePayloadPrivacyTest` | none |
| P89-SEC-003 | Medium | **FIXED** | `Queue::before` clears Spatie's permission collection before every job, so a worker decides on the current role permissions. | `4607de3`; `tests/Feature/Reliability/WorkerPermissionFreshnessTest.php` | none |
| P89-SEC-004 | Medium | **FIXED** | `AuthorityGuard::protecting()` opens the transaction and locks the CHRO role row first. The remaining effective CHROs are then counted with a locking read. Two concurrent removals are serialised, and the second sees the first. | `3797c71`; MySQL race test in `tests/Concurrency/IntegrityRaceTest.php` (fails on the baseline) | none |
| P89-SEC-005 | Medium | **FIXED** | `.dockerignore` excludes `storage/app/private/*` and `storage/app/public/*`. Feature tests now run on faked disks and no longer write into `storage/app` (P89-DQ-016). | `88c10b8`, `95b2f58`, `d8f9e3e`; a full suite run leaves no new file under `storage/app` | Files written by earlier test runs are still on this development host (offer-letter PDFs). They are excluded from builds and were left for the developer: deleting files is not done without a request. At the last `storage:audit` there were 277; `storage:audit --list` lists them. |
| P89-SEC-006 | Medium | **FIXED in the shipped compose file**; production unverified | `MYSQL_ALLOW_EMPTY_PASSWORD` removed; `MYSQL_RANDOM_ROOT_PASSWORD: "yes"`. | `88c10b8`; `DeploymentTopologyTest` | Whether production uses this compose file is unknown (D8.9-026); `production-environment.md` lists it. |
| P89-SEC-007 | Low | **FIXED** | `StaffDatabaseSessionHandler` records `user_id` only for staff sessions. A candidate session stores none, so revoking a staff user's sessions can never end a candidate's. | `88c10b8`; `tests/Feature/Security/SEC8907SessionOwnershipTest.php` | Candidate rows written before deployment keep their old `user_id` until they expire (historical data is not repaired). |
| P89-SEC-008 | Low | **FIXED** | Apache logs without the query string or Referer and adds `X-Request-Id` and the duration. Every channel in `config/logging.php` that writes records has the redaction tap, except `emergency` (see the residual): the file channels `single`, `daily` and `monthly`; the Slack webhook channel `slack`; the remote-syslog channel `papertrail` (Monolog UDP syslog handler); the `stderr` stream; `syslog`; and `errorlog`. `stack` only forwards to these channels, and `null` discards records. The default is `daily`, at level `info` in production. | `88c10b8`; `docker/apache/000-default.conf`; `config/logging.php` | The framework `emergency` channel (used only when logging itself fails) accepts no tap; **accepted**. |
| P89-SEC-009 | Low | **FIXED** | The Zoom server-to-server token is cached encrypted, under a new key (`zoom:s2s-token:v2`). The old plaintext entry expires within 50 minutes. | `88c10b8` | none |
| P89-SEC-010 | Low | **FIXED (application part)** | Per-user throttles: `files/private` 300/min; calendar OAuth routes 20/min. | `88c10b8`; route list | The careers index and feed remain **SEC-88-21 / PF-88-10, deferred (C)**. The collapse of IP limits behind a proxy remains **SEC-88-10, deferred** on the stated topology. |
| P89-SEC-011 | Low | **FIXED** | `SendTimeGuard::senderReason`: the sending staff member must still hold `communications.send` and still see the candidate when the message is sent. Otherwise the message is Blocked with the reason and audited (never Failed, never sent). | `094bd04`; `CommunicationDeliveryIntegrityTest` | none |
| P89-SEC-012 | Info | **FIXED (queue)**; payload **accepted** | Filament exports run on the named `exports` queue (`RunsOnExportQueue`). | `4607de3`; `QueueTopologyTest` | The export jobs are Filament's own classes and carry a serialized query and column map (no row contents) unencrypted. **Accepted:** metadata only, as found in discovery. |

**Carried forward, not new:**
- The production delete-authorization gap (Phase 8.6 SEC-1, Critical) remains open **in production**. See §6.3.
- The four High data-integrity races P89-DQ-001…004 are **fixed** (`b55683e`), proven on MySQL by `tests/Concurrency/IntegrityRaceTest.php`. Each race test fails on the baseline code and passes now. DQ-005, DQ-006, DQ-009, DQ-010, DQ-011 and DQ-012 are also fixed. See `phase-8-9-implementation.md`.

### 6.2 New code reviewed

| Change | Security review |
|---|---|
| Queued interviewer import (`ImportInterviewersJob`) | The payload is the stored path and the user id only. When the job runs it re-checks the requester's access (`StaffAccessService::permits`) and `create` on `Interviewer`; without them it imports nothing. The uploaded file is deleted in every outcome. The result arrives as an encrypted staff alert. |
| Queued Word→PDF conversion (`ConvertOfferLetterJob`) | The payload is the conversion id only. The filled `.docx` stays on the private disk. `failed()` stores a redacted error (`SensitiveDataRedactor::text`) and alerts the releaser. The issued letter keeps its SHA-256, and `issued_at` is the release time. Downloads still need `view` plus `compensation.view`. |
| Lazy, bundled dashboard widgets | The visible widgets are still filtered by `canView()` (`AuthorizesWidget`). A lazy load can only mount a component from a server-signed snapshot that was issued to the same user. The position-health memo is keyed per viewer and lives for one request or job (scoped binding). It is never shared across requests or users. |
| Candidate scope semi-join / covering index / exact search | The same visibility as before (applications of visible recruiters, plus candidates the user created). On the benchmark, the old and new scope return identical candidate counts for a manager and a recruiter at every tier (`phase-8-9-performance.md` §9). The visibility tests pass unchanged. Exact search adds no new field; email search stays off where it was off (Command Palette, Candidate Picker). |
| `storage:audit` | Console only; read-only. Paths are printed only with `--list`; no file contents. |
| `cache:prune-expired` and the housekeeping schedule | Deletes only expired cache rows, expired reset tokens and old finished batches. A live lockout, step-up code or heartbeat is never touched (tested). |
| Career-site submission lock | The key is a SHA-1 of the normalized email and mobile (no plaintext in the cache key). A submission still waiting after the lock gets the same neutral response (SEC-88-09). The log line carries the posting id only. |
| Conditional automation cancel / finish, performance snapshot freeze, assignment locks | Integrity only. No authorization path changed. |
| Observability (`queue.job_processed`, heartbeats, `/up`) | Logs job class, queue, attempt and duration; never payloads. Heartbeats are timestamps. `/up` returns no detail. |

### 6.3 Hotfix `2fab3fd` — re-checked, not merged

- `2fab3fd` ("Security hotfix: close Filament's missing-policy-method delete bypass") exists only on `hotfix/filament-delete-authorization`.
  - It is **not** an ancestor of this branch.
  - It is **not** in `main` or `production`, which are both still at `9cba8e3`.
  - It was **not merged** in this phase: merging it is outside the approved scope and needs its own release decision (D8.9-027).
- This branch closes the same gap differently. Filament strict authorization (`AdminPanelProvider`; `tests/Feature/Security/StrictAuthorizationTest.php`) also carries SEC-86-I-01.
- The hotfix branch still lacks SEC-86-I-01. It must not be deployed without it (Phase 8.8 freeze §11).
- **The production exposure is unchanged.** It is an open production-release item (P89-OPS-012), not a defect of this branch.

### 6.4 Security regression

On the final application commit `1acd789`:

| Check | Result |
|---|---|
| Full suite, parallel and serial, including the 27 files in `tests/Feature/Security` and the reliability, privacy and identity suites | **2,064 / 2,064** passed each way, 0 risky |
| MySQL concurrency suite (DQ-001…006, lock order, SEC-004) | **8 / 8** in seven consecutive runs. Two earlier runs stopped during the harness's database setup; see `phase-8-9-implementation.md` §12. Against the baseline code: **8 / 8 fail**. |
| Browser matrix, Phases 6 – 8.9 | **202 / 202**, including the 8.8 containment (7 / 7) and authentication (19 / 19) smokes, and the SEC-001 checks in the 8.9 smoke |
| Checked against pre-fix code | **Fail before the fix, pass after:**<br>• the eight MySQL races (DQ-001…006, lock order, SEC-004), run against the baseline worktree;<br>• DQ-009, DQ-010, DQ-012;<br>• the reliability-sweep bound, the queued portal alert and the talent-pool cap;<br>• the Word-download fix, the dashboard on-load trigger and the audit actor-kind restore (each with its fix reverted).<br>**DQ-011:** not checked against pre-fix code when this review was first written, although an earlier version of this row said it was. It was checked during the freeze verification (2 October 2026, 21:23 IST): `app/Services/Distribution/CareerApplicationService.php` was temporarily restored from `5086ffc^`, and the DQ-011 test in `SEC8801AnonymousEmailCannotMutateCandidateTest` failed. With the file restored to its committed version, it passed. |

Full verification: `phase-8-9-implementation.md` §12.

### 6.5 Summary after implementation

| | Fixed | Fixed with accepted residual | Deferred (existing ID) | Open |
|---|---|---|---|---|
| High (1) | SEC-001 | — | — | 0 |
| Medium (5) | SEC-002, 003, 004, 005 | SEC-006 (production unverified) | — | 0 |
| Low (5) | SEC-007, 009, 011 | SEC-008 (`emergency` channel), SEC-010 (careers: SEC-88-21; proxy: SEC-88-10) | — | 0 |
| Info (1) | — | SEC-012 (payload metadata accepted) | — | 0 |

**No unresolved High or Critical security finding remains in this branch.** The carried-forward production gap (§6.3) stays open until the release decision D8.9-027.
