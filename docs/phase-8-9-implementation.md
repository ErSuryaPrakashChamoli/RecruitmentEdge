# Phase 8.9 Implementation — Enterprise Scale, Performance, Observability & Operational Readiness

**For:** the project owner, security, operations and the engineers who take over this branch.

**Status:** the implementation is complete. Verification is in §12, the freeze decision in `phase-8-9-freeze.md`.
- Nothing was pushed or deployed. Production was not touched.
- No historical data was repaired. No retention, metric definition or audit row was changed.
- Phase 8.10 was not started.

| | |
|---|---|
| Branch | `feature/sep_25_hrm` |
| Baseline | `dce11d9` (Phase 8.8 freeze); discovery documents `ecd2b91` |
| Final application commit | `1acd789` |
| Approval | the Phase 8.9 implementation brief (2026-10-02), "IMPLEMENTATION APPROVED" |
| Related documents | `phase-8-9-security-review.md` §6 · `phase-8-9-performance.md` §9 · `phase-8-9-operational-readiness.md` §8 · `phase-8-9-decision-record.md` (Implementation record) · `docs/backlog.md` (P88 / P89) · runbooks in `docs/runbooks/` |

Finding IDs are the discovery IDs (P89-SEC / DQ / PERF / OPS). Engineering defaults are ED-xx; decisions taken inside the approved scope are I8.9-xx (decision record).

## 1. Security (brief §4, §12, §20)

| Finding | Change | Commit | Tests |
|---|---|---|---|
| **P89-SEC-001** (High) | Daily targets are scoped to the hierarchy (`RecruitmentDailyTarget::visibleTo` / `isVisibleTo`) in the resource query and the policy. Every write goes through the new `RecruitmentTargetService` (Gate + scope per record). Bulk delete authorises each record and deletes through the service. The form lists visible employees only. | `d939533` | `SEC8901TargetScopeTest` |
| SEC-002 | Encrypted `App\Notifications\Auth\VerifyEmailChange` on the `security` queue | `4607de3` | `CredentialLifecycleTest`, `QueuePayloadPrivacyTest` |
| SEC-003 | The Spatie permission map is cleared before every job | `4607de3` | `WorkerPermissionFreshnessTest` |
| SEC-004 | Last-CHRO protection runs serialised on the CHRO role row (`AuthorityGuard::protecting`) | `3797c71` | MySQL `IntegrityRaceTest` |
| SEC-005 … 010 | Build context excludes `storage/app`; random MySQL root password; staff-only session `user_id`; Apache log without query strings; every file log channel redacts; encrypted Zoom token cache; throttles on `files/private` and calendar OAuth | `88c10b8` | `SEC8907SessionOwnershipTest`, `ObservabilityTest`, `DeploymentTopologyTest` |
| SEC-011 | The send-time guard re-checks the staff sender (`communications.send` + candidate visibility) | `094bd04` | `CommunicationDeliveryIntegrityTest` |
| SEC-012 | Exports on the named `exports` queue | `4607de3` | `QueueTopologyTest` |

Dispositions, residuals and the `2fab3fd` re-check: `phase-8-9-security-review.md` §6.

## 2. Data integrity (brief §5, §7)

All of this uses `App\Services\Lifecycle\RowLock`:
- a locking read rebuilds the model, because at REPEATABLE READ a plain `refresh()` after a lock returns a stale snapshot;
- the lock order is always **application first**, which fixes the P89-PERF-021 inversion.

| Finding | Change | Commit |
|---|---|---|
| **DQ-001** incentive payments / transitions | `IncentiveApprovalService` locks the calculation and decides transitions, payments, adjustments and reversals on the locked row. Alerts are sent after commit. | `b55683e` |
| **DQ-002** incentive calculator | Each rule's pricing is serialised on the rule row; the calculator reads with locks | `b55683e` |
| **DQ-003** joining transitions | `CandidateJoiningService` decides on the locked joining (and the application first) | `b55683e` |
| **DQ-004** offer terms after acceptance | Offer creation, revision request / release and `updateTerms()` lock the application, then the offer; Filament edits go through `updateTerms()` | `b55683e` |
| DQ-005 / 006 | Every `StageTransitionService` mutation is locked; offer creation re-checks the blocker inside the lock | `b55683e` |
| DQ-009 | Move-to-requisition and reassignment decide on the locked application; a duplicate application is refused readably | `776325f` |
| DQ-010 | Automation `cancel()` / `finish()` are conditional writes (they win only against the status they expect) | `d9ac3a9` |
| DQ-011 | Career-site submissions with the same contact details are decided one at a time; a waiting one gets the neutral response | `5086ffc` |
| DQ-012 | A frozen performance month is never rewritten by a recompute that read it before the freeze | `4ea4a4f` |
| DQ-014 | Limit-skipped automation runs are pruned (in batches) | `a5984ab` |
| DQ-015 | Talent signals page through every active application (stale ones first, up to the limit) | `8705b86` |
| DQ-016 | Feature tests run on faked disks; nothing is written to `storage/app` | `95b2f58`, `d8f9e3e` |
| PERF-021 lock order | application → interviews / offers / joining / incentives everywhere | `b55683e` |
| PERF-021 deadlock on first-time outcomes | `OutcomeService::record` retries its own transaction (up to 3 attempts) when MySQL picks it as a deadlock victim. Two first-time outcomes take compatible gap locks, then deadlock on insert. This was seen in the MySQL race suite, where the outcome listener runs inline in both processes. | `cc6da61` |

**Proven on MySQL.** `tests/Concurrency` (ED-10) runs each race on MySQL 8.4 with two processes. It covers DQ-001…006, the lock order and SEC-004. Every race test fails on the baseline code and passes now.

**Not changed:**
- DQ-007 (slot bookings, with SEC-88-20, deferred);
- DQ-008 (blind Filament edits, a Product UX decision);
- the rest of DQ-013 (small races);
- DQ-017 (alert intent, Product);
- DQ-018 (documentation; done in the backlog).

## 3. Governed metrics — P0 failures (brief §6)

| Finding | Change | Semantics |
|---|---|---|
| PERF-027 placeholder failure (`joining.offer_to_join`, distribution analytics) | Subqueries and grouping instead of bound id lists | unchanged |
| PERF-028 memory (`hiring.time_to_hire`, `sla.leg_compliance`) | Paged by id; running statistics instead of whole-history arrays | unchanged |
| PERF-029 quadratic paging (`pipeline.time_in_stage`) | `chunkById` over the scoped applications | unchanged |

Commits `5c39c0d`, `05f6863`, `1acd789`.

**Found by the implementation benchmark (same family):**
- `pipeline.time_in_stage` still copied every duration through `Collection::median()` (426 MB at 1M);
- `source.source_to_join` hydrated every joining of the period with its relations (314 MB at 1M).

Both now use plain lists sorted in place (`medianSortingInPlace` / `medianOfSortedLists`) or counting while paging: +102 MB and 7.4 MB at 1M.

**Parity**
- The four metrics (CHRO and manager × this month / 90 / 365 days) and the distribution analytics were compared, old code against new, on the benchmark: **the JSON outputs are byte-identical**, for both the query changes and the in-place medians.
- `source.source_to_join`: every figure is identical. Sources tied on sample size may change order: the old order came from MySQL's unordered rows.

**Guard.** `MetricScaleShapeTest` guards the shapes: no id list bound, no offset paging, joinings read in pages, and helpers equal to `Collection::median()` / `avg()`.

**Untouched:** definitions, populations, numerators, denominators, anchors, scope and unknown handling.

## 4. Application performance (brief §7, §8, §14, §15)

| Finding | Change | Commit |
|---|---|---|
| PERF-012 (ED-01) | `HierarchyMemo`, scoped per request / job; flushed by `EmployeeObserver`; 60 s TTL | `ad2a428` |
| PERF-002 (ED-02) | Candidate visibility is `id IN (applications of visible recruiters UNION created by the user)` | `217b116` |
| PERF-003 | Exact identifiers (candidate code, normalized email, phone of 10 or more digits) use the normalized indexes in table search, global search, the Command Palette and the Candidate Picker. Substring search remains the fallback; no engine. | `217b116` |
| PERF-010 | Pipeline: recruiter options from a distinct subquery; the sourced count once; "interviews today" as a range | `217b116` |
| PERF-011 (ED-03) | Indexes justified by EXPLAIN:<br>• `candidate_applications (status, current_stage, last_activity_at)`;<br>• `candidate_stage_histories (created_at)`;<br>• `audit_logs (created_at)`;<br>• `audit_logs (action, created_at)`;<br>• `automation_executions (automation_rule_id, started_at)`;<br>• the covering index `candidate_applications (recruiter_id, deleted_at, candidate_id)` for the candidate scope. | `217b116`, `a5984ab`, `06b105a` |
| PERF-013 | Audit list: simple pagination, a `created_at` range filter, indexed sort and filter. No retention change. | `217b116` |
| PERF-015 (ED-09) | Dashboard: the day's numbers paint first. The other widgets start on page load and arrive in **one** bundled request (`lazy.bundle` = `on-load`). Position health is computed once per request. | `ab747a4`, `ad43ea9` |
| PERF-022 | Incentive effective amounts from a preloaded adjustment total | `69b96ca` |
| PERF-024 | No heavy work in requests:<br>• Word→PDF on the `documents` queue (`ConvertOfferLetterJob`, `OfferLetterConversion`);<br>• interviewer import on `documents` (`ImportInterviewersJob`);<br>• portal-upload recruiter alert queued (E-14);<br>• talent-pool additions capped at 500 per request. | `50f434f`, `a0e58ec`, `625eabf`, `447ee7c`, `487f30b` |

Before / after figures: `phase-8-9-performance.md` §9.

## 5. Queues (brief §9)

| Worker | Queues | Change |
|---|---|---|
| `queue` | communications, default | — |
| `queue-priority` (**new**) | security, notifications, default | OTP / step-up codes, password reset, email-change verification and notice, and portal links run on `security`; in-app and platform alerts on `notifications`. None of them waits behind candidate messages (ED-05). |
| `queue-automation` | automation, default | — |
| `queue-background` | documents, intelligence, integrations, exports, default | `documents` (Word→PDF, interviewer import) and `exports` (ED-06) added |

**Other changes:**
- Payloads stay ids-only and encrypted.
- `QueueHealthService` watches every queue.
- Worker permissions are fresh per job (SEC-003).

Commits `4607de3`, `50f434f`, `a0e58ec`.

## 6. Scheduler (brief §10)

| Finding | Change | Commit |
|---|---|---|
| PERF-004 `dispatch-alerts` memory | SLA breach sweeps page the applications at each leg's stage by id. Entry times are computed per page and status is checked per page (`eachOpenBreach`). The other checks use `lazyById(500)`. Bounded memory and linear time: 139 s / 48 MB at 1M. | `8705b86`, `74b7295`, `d6eee3c` |
| PERF-005 intelligence cadence | Stalest requisitions first, within a time budget (`INTELLIGENCE_REFRESH_TIME_BUDGET`, 2,700 s). The rest is logged and deferred. The Risk Radar skips deferred requisitions and never auto-resolves their risks. | `8705b86` |
| PERF-014 outcome full history | Learning insights fold outcomes into counters while streaming. The evaluator was already windowed (`outcomes.catch_up_days`). | `8705b86` |
| PERF-023 talent signals | One query per page; only stale applications are recalculated | `8705b86` |
| PERF-007 reliability sweep (ED-04) | At most `COMMUNICATIONS_REQUEUE_MAX_PER_RUN` (1,000) held messages re-queued per run, oldest first | `cbc5475` |
| OPS-009 (ED-08) | Daily technical housekeeping: expired cache rows, reset tokens, finished batches | `9ef9c1b` |

## 7. Automation storm control (brief §11)

| Finding | Change | Commit |
|---|---|---|
| PERF-008 | A rule that has reached its daily limit records the skip when the event happens and queues no job. The limit is checked again when the run is due. | `a5984ab` |
| PERF-009 index | The daily-limit count is served by `automation_executions (automation_rule_id, started_at)` | `a5984ab` |
| DQ-010 | Conditional `cancel()` / `finish()` | `d9ac3a9` |

Versioning, audit, owner authority, idempotency and escalation are unchanged; their existing tests pass.

**Constraint.** The per-record limit and cooldown assume **one** `queue-automation` process (documented in the runbook and in `.ai/rules/automation.md`).

## 8. Communications (brief §12)

At send time, a message sent by a staff member is Blocked (with the reason, audited) when that person no longer holds `communications.send` or can no longer see the candidate. Commit `094bd04`.

## 9. Storage (brief §13)

- `storage/app/private/*` and `storage/app/public/*` are excluded from the Docker build context (SEC-005).
- `php artisan storage:audit` (read-only) reports:
  - size per area (growth);
  - files no record references (orphans);
  - records whose file is missing.
- Nothing is deleted. Orphan cleanup waits for the retention decision.
- Private-file and signed-URL behaviour is unchanged (`SEC8817PrivateFileAccessTest`).
- The backup strategy is in `docs/runbooks/backup-restore.md`. It states that no backup system exists.

## 10. Observability, failure recovery, deployment (brief §16–§19)

**Logs**
- `daily` rotation that deletes nothing until retention R-13; level `info` in production; redaction on every file channel.
- The Apache log carries the request id and duration and no query strings.

**Correlation**
- Every job logs `queue.job_processed` (class, queue, attempt, duration) and carries `job` context on its log lines.
- The audit actor kind is restored after every command and job, however it ends (`2c1e014`).

**Health**
- `/up` checks the database.
- Every worker process writes a heartbeat. `ops:heartbeat` is the container health check of each worker and of the scheduler.
- `/health/queue` and `queue:health-check` report a silent worker or a scheduler that has never reported (`QUEUE_EXPECT_PROCESSES`).

**Deployment** (compose):
- the one-shot `migrate` service runs first; then a healthy `app` (restart policy, 130 s grace); then healthy workers; then the scheduler;
- `RUN_MIGRATIONS=false` everywhere;
- the image is tagged by `APP_IMAGE_TAG`, so rollback is by tag;
- ownership is fixed once per volume;
- deploys never clear the cache store.

**Runbooks:** `queue-operations.md` (revised), `backup-restore.md`, `production-environment.md`, `incident-recovery.md`.

**Not claimed:**
- high availability, DR, zero downtime;
- RTO / RPO;
- automatic restart of a hung but running container (Compose restarts only exited ones);
- that a backup exists.

## 11. Operating notes for whoever deploys this

**Configuration and processes**
- New settings in `.env.example`:
  - `QUEUE_EXPECT_PROCESSES`;
  - `INTELLIGENCE_REFRESH_TIME_BUDGET`;
  - `COMMUNICATIONS_REQUEUE_MAX_PER_RUN`;
  - `APP_IMAGE_TAG`;
  - `LOG_STACK=daily`, `LOG_DAILY_DAYS=0`.
- The `queue-priority` worker must run; otherwise OTP, reset and alert traffic stops.
  - With `QUEUE_EXPECT_PROCESSES=true`, `queue:health-check` detects the silent worker.
  - Its in-app alert cannot arrive while that worker is down: the alert is written by a job on the `notifications` queue, which only `queue-priority` serves.
  - The condition is visible in the log (the `platform.alert` warning; the command also exits non-zero) and at `/health/queue`.
- Keep `queue-automation` at one process.

**Data and files**
- Four migrations, all additive: three index migrations and `offer_letter_conversions`. They roll back cleanly (§12).
- Word offer letters exist as PDFs a minute or two after release. Until then, the download says "being prepared".

**User-visible changes**
- Interviewer imports report by alert.
- Talent-pool additions are capped at 500 candidates per request.

## 12. Verification

All runs below are on the final application commit `1acd789`, except where marked. Every database used is a throwaway database on the development MySQL 8.4 server.

| Check | Result |
|---|---|
| Full suite, parallel (`php artisan test --parallel`) | **2,064 passed, 0 failed, 0 risky; 21,555 assertions** (baseline `dce11d9`: 1,979 / 21,010) |
| Full suite, serial | **2,064 passed, 0 failed, 0 risky; 21,555 assertions** |
| Files written to `storage/app` by either run | **0** (P89-DQ-016) |
| MySQL concurrency suite (`phpunit.concurrency.xml`, 2 processes, real InnoDB locks) | **8 / 8 passed in 7 consecutive runs** on the final code. Two earlier runs that day stopped inside the harness's own `migrate:fresh` ("table already exists" / "doesn't exist", and in one of them a deadlock on `DROP TABLE`). No race test ran in those two, and the cause was not established. Against the baseline application code (a temporary worktree at `dce11d9` with these tests): **8 / 8 fail**. |
| Browser regression matrix (real Chromium, 8.9 four-worker topology) | **184 / 184** across Phases 6 – 8.8, plus **18 / 18** for Phase 8.9 = **202 / 202** (§12.1) |
| Phase 8.9 browser smoke (18 checks) | **18 / 18**, no console or page errors, no HTTP 5xx (§12.1) |
| `migrate:fresh --seed` on a new throwaway MySQL database | OK, 164 migrations (baseline 160 + 4), about 73 s |
| Roll back the four Phase 8.9 migrations, then migrate again | OK: 164 → 160 (the table and indexes removed) → 164 (restored) |
| Routes | **239** (unchanged since Phase 8.8) |
| `npm run build` | OK |
| Pint (every PHP file changed since the baseline) | passed |
| `git diff --check` | clean (three trailing-space lines in the discovery docs fixed) |
| `config:cache`, `route:cache`, `view:cache`, `event:cache` | OK, then cleared (only the compiled files; the cache store was not touched) |
| Static analysis | not installed in the project — **not run** |
| `storage:audit`, `cache:prune-expired --dry-run` on MySQL | ran read-only on the throwaway database |
| Secrets | none in the diff; `.env` not committed |

**Change size.** 48 commits on top of `dce11d9`, 179 files changed (application, config, migrations, deployment, tests, rules and documents).

**Defects found by the verification itself and fixed before the final runs:**
- a download during the Word conversion returned a type error (`625eabf`);
- off-screen deferred widgets waited for scrolling instead of loading with the page (`ad43ea9`);
- the audit actor kind leaked between commands and jobs (`2c1e014`; now guarded for every feature test);
- a gap-lock deadlock on first-time outcome records, seen in the MySQL race suite (`cc6da61`);
- test-order dependences around Livewire's lazy-loading switch (`1dd4125`).

**Found by the scale re-validation (§3–§6; `phase-8-9-performance.md` §9.3):**
- the alert sweep was quadratic (`74b7295`, `d6eee3c`);
- two metrics still materialised every row (`05f6863`, `1acd789`);
- the candidate scope lacked its covering index (`06b105a`).

### 12.1 Browser regression

**Setup**
- Real Chromium (Playwright), each phase on its own throwaway MySQL 8.4 database, dropped, re-migrated and re-seeded.
- Workers run the shipped Phase 8.9 topology: `communications,default` · `security,notifications,default` · `automation,default` · `documents,intelligence,integrations,exports,default`.
- 8.6, 8.7 and 8.8 run on the sync queue, as designed.
- The final run was against `1acd789`; the same matrix had also passed on `ad43ea9`.

| Phase | Result |
|---|---|
| 6 | **24 / 24**, no problems |
| 7 (AI keys empty) | **20 / 20**, no problems |
| 8.1 | **12 / 12**, no problems |
| 8.2 | **20 / 20**, no problems |
| 8.3 | **17 / 17**, no problems |
| 8.4 (MFA enforced, database sessions) | **19 / 19**; only the known `showModal` console message, logged since the 8.4 baseline |
| 8.5 | **15 / 15**, no problems |
| 8.6 (sync queue) | **16 / 16** |
| 8.7 | **15 / 15** |
| 8.8 containment | **7 / 7** |
| 8.8 authentication | **19 / 19** |
| **8.9** (new) | **18 / 18**, no problems — see the note on check 9 below |

**What the 8.9 smoke checks:**
- first paint vs deferred widgets: one bundled request carries all 15 deferred widgets;
- exact search by code and by email in another case;
- interviewer import on the `documents` worker: interviewer added, uploader alerted, upload removed;
- Word release without waiting for LibreOffice: "being prepared" during the conversion, the PDF issued with its SHA-256, then downloaded;
- queue health lists `security` / `documents` / `exports`;
- the audit log renders;
- `/up` returns 200;
- a manager sees only their team's targets (SEC-001);
- the pipeline board renders;
- no failed and no leftover jobs.

**Note on check 9.** In the final run the documents worker finished the conversion before the download click, so the "being prepared" branch was not reached in the browser. It was reached in the earlier browser run (`ad43ea9`), and it is covered by `WordOfferLetterTemplatesTest`.
