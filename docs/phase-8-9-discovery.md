# Phase 8.9 Discovery: Enterprise Scale, Performance, Observability & Operational Readiness

**Status:** DISCOVERY ONLY.
- No application code, migration, route, index, queue topology, scheduler, worker or authentication change was made.
- Nothing was pushed or deployed.
- Phase 8.10 has not started.

**Baseline:** `feature/sep_25_hrm` @ `dce11d9` (Phase 8.8 freeze; application commit `05a9fd3`).

**Data labels used in every Phase 8.9 document:**

| Label | Meaning |
|---|---|
| **ACTUAL** | measured on this system's own data. The development database is the only one reachable. **Production was not accessible** (D8.9-026). |
| **BENCHMARK** | measured on throwaway MySQL 8.4 databases (`hrms_p87_perf` copied into `hrms_p89_bench`, scaled to 500k and 1M), running the real application code. Each was dropped afterwards. |
| **PROJECTED** | modelled from code paths and benchmark slopes. Never presented as a measurement. |

**Document map:**

| Document | Contents |
|---|---|
| this file | baseline verification, phase reconciliation, the investigation areas A–T, concurrency, data growth, data-quality findings (P89-DQ), finding index, full backlog reconciliation, non-goals, completion gate |
| `phase-8-9-performance.md` | measured tiers, query hotspots, index gaps, queue / scheduler / automation / communications models, search and analytics, P89-PERF |
| `phase-8-9-security-review.md` | security at scale, P89-SEC |
| `phase-8-9-capacity-model.md` | volumes (ACTUAL / BENCHMARK / PROJECTED), tier model, table growth, benchmark plan |
| `phase-8-9-operational-readiness.md` | observability, failure recovery, deployment, backup/DR, files, P89-OPS |
| `phase-8-9-decision-record.md` | D8.9-001 … 031; engineering defaults ED-01 … 13. All proposed, none approved. |

**Method:**
- Code reading with path:line evidence, done by five parallel read-only reviews:
  - schema and query patterns;
  - concurrency;
  - queue, scheduler and automation;
  - operations;
  - backlog.
- Every finding that carries a severity was re-checked in the code by hand.
- `EXPLAIN` and timed probes of real application code paths, run on the throwaway databases only.

---

## 1. Baseline verification

| Check | Result |
|---|---|
| Branch / HEAD | `feature/sep_25_hrm` @ `dce11d9` ✔ |
| Phase 8.8 freeze | `docs/phase-8-8-freeze.md` reads "Status: FROZEN" ✔ |
| Application tree vs `05a9fd3` | identical for `app routes bootstrap config database resources tests` ✔ |
| Working tree at start | clean ✔. At the end of discovery only the Phase 8.9 documents are added. |
| Migrations | **160**; **0 pending** on the development database ✔ |
| Routes | **239** ✔ (matches the freeze) |
| Test suite (re-run at the start of 8.9, parallel ×4) | **1,979 passed, 21,010 assertions**, 0 failed (353 s) ✔ |
| Hotfix `2fab3fd` | still **not** an ancestor of `main` / `production` (both `9cba8e3`), so the production delete-authorization gap is carried forward (D8.9-027) |

## 2. Phase reconciliation (8.5 – 8.8)

| Phase | Freeze | Delivered | Carried into 8.9 (see §10) |
|---|---|---|---|
| 8.5 Metric governance | frozen | MetricRegistry (28 keys), MetricService cache (600 s, scope fingerprint), 14 definition fixes, 7 performance fixes | P85-006 (SLA sweep hydration), P85-007 (stage-entry fact table), P85-003 (cache by expiry only), 8.5 PF-10/11 |
| 8.6 Governance & audit | frozen | audit coverage, historical reproducibility, master-data lifecycle, SEC-1 fix **on the branch** | P86-010 (settings-aware metric cache key); P86-007 hotfix release (deployment) |
| 8.7 Reliability | `phase-8-7-freeze.md` | queue topology (3 workers), ids-only payloads, reliability sweep, platform alerts, request/actor correlation | P87-004 / 005 / 006 / 009 / 010 / 011; 8.7-KL-1 (no load test), KL-2 (shared cache store) |
| 8.8 Authentication foundation | `dce11d9` | candidate authentication model (D8.8-001), export governance (10,000-row cap, 24 h owner-only download), security headers, signed private files, upload hardening | PF-88-01 … 12. **SEC-88-02 stays deferred to the dedicated retention phase.** Accepted (B): SEC-88-05/07/14. Deferred (C): SEC-88-10/16/18/20–23/25–28. None is reclassified here. |

## 3. Scale summary

ACTUAL (development database, 2026-10-01):

| | Count |
|---|---|
| Candidates / applications / requisitions (open) | 7 / 6 / 6 (0) |
| Employees / users | 23 / 11 |
| Interviews / offers | 1 / 1 |
| Audit rows | 182 |
| AI conversations / messages / tool calls / action logs / usage logs | 2 / 16 / 5 / 13 / 44 |
| Jobs / failed jobs | 6 (one stale for 17 days) / 0 |

These numbers say nothing about production. **Production volumes are unknown** (P89-OPS-003, D8.9-026).

The only documented supported envelope is the Phase 8.7 runbook statement: about 100k applications and 500 open requisitions on the database queue.

BENCHMARK and PROJECTED, in short. The full tables are in `phase-8-9-performance.md` §2 and `phase-8-9-capacity-model.md`.

| Tier | Interactive paths (lists, 360, portal, Action Center) | Organisation-wide analytics | Background |
|---|---|---|---|
| 10k – 50k (PROJECTED) | comfortable | organisation-wide 365-day metrics already take seconds | fine |
| **100k (BENCHMARK)** | hierarchy-scoped candidate list 0.5–0.8 s (full scan); everything else ≤ 0.25 s | **53.6 s** for all governed metrics (365 days, cold, CHRO); `pipeline.time_in_stage` 31.9 s; `sla.leg_compliance` 11.8 s | `dispatch-alerts` SLA sweep 585 MB > 256 MB limit (8.5 measurement); intelligence refresh 414 s cold |
| **500k (BENCHMARK)** | manager / recruiter candidate list **3.1–3.5 s**; name search (manager) **4.0 s**; Action Center (manager) 1.1 s | this month alone: **26 s** (CHRO). **The 365-day set fails** (MySQL 1390, P89-PERF-027). `sla.leg_compliance` 181 s. Manager scope 15–24 s. | intelligence refresh ≈ 35 min cold (PROJECTED) |
| **1M (BENCHMARK)** | manager / recruiter candidate list **≈ 7 s**; manager name search **8.0 s**; Action Center (manager) **4.0 s**; indexed paths still ≤ 3 ms | this month **83 s**. `offer_to_join` fails (1390). `time_to_hire` **395 MB** and `sla.leg_compliance` **446 MB / 880 s** exceed the 256 MB limit. `time_in_stage` did not complete (> 22 min). | intelligence refresh > 1 h, so it exceeds its hourly cadence (PROJECTED) |

**There is no evidence for an enterprise-readiness claim at 500k or 1M.** Readiness also depends on decisions that do not exist yet: supported scale, SLOs, RTO and RPO (D8.9-001 … 010).

---

## 4. Investigation areas A–T

Each area lists what was found and the finding IDs. Evidence is in the referenced document.

### A. Database

- **Engine and configuration:**
  - MySQL 8.4, REPEATABLE READ;
  - single primary;
  - no replica, partitioning or MySQL configuration in the repository.
- **Index review:** full inventory in `phase-8-9-performance.md` §3.
  - **31 composite-index gaps** (P89-PERF-011);
  - one redundant index (`intelligence_evidence` owner morph prefix).
- **Full scans confirmed with `EXPLAIN` at 100k / 500k:**
  - hierarchy-scoped candidate list (OR plus dependent subquery, P89-PERF-002);
  - `LIKE '%x%'` search (P89-PERF-003);
  - `candidate_stage_histories` created_at ranges (P89-PERF-001);
  - organisation-wide application list count;
  - audit list sort / filter;
  - `whereDate` predicates.
- **Locks:**
  - 24 `lockForUpdate` sites, no `sharedLock`;
  - **lock-order inversion** between offer / interview transitions (offer → application) and the closure cascade (application → interviews → offers);
  - `code_sequences` serialises every code-generating insert, including career-site bursts (P89-PERF-020, 021).
- **Growth:** see §7. Nothing is pruned except `failed_jobs` (720 h) and condition-failed automation runs.

### B. Application

- **HierarchyService:**
  - `visibleEmployeeIdsFor` is not memoized;
  - it is called from 87 places, several times per metric (P89-PERF-012).
- **Pipeline page:** `recruiterOptions` hydrates every scoped application on every Livewire round-trip, and the "sourced" count repeats for ~11 columns (P89-PERF-010).
- **Dashboard:** 16+ widgets, mostly non-lazy and uncached; `positionHealth` is computed 3–5× per load (P89-PERF-015).
- **N+1 in tables:**
  - interviews, joinings, offers, requisitions;
  - `effectiveAmount()` per row (8.5 PF-10, P89-PERF-022);
  - per-row policy checks repeat closure-table queries.
- **No lazy-loading guard** (`preventLazyLoading` is absent).
- **Synchronous heavy work in requests:**
  - LibreOffice Word→PDF (up to 120 s) and offer-letter PDF rendering inside the release transaction;
  - interviewer import;
  - portal-upload listener;
  - talent-pool bulk add without a cap (P89-PERF-024).
- **Memory:** `memory_limit = 256M` (`docker/php/local.ini:2`) applies to web, scheduler and workers alike. Workers also have the 128 MB `queue:work` default.

### C. Queue

- **Topology:** database driver; 3 single-process workers; strict priority order within each worker.
- **Starvation:** `notifications` waits behind `communications`. That queue carries:
  - step-up OTPs (10 min TTL) and password resets (60 min);
  - portal links;
  - every in-app alert, including the queue-health alert itself (P89-PERF-006, P89-OPS-002).
- **Burst of 100k candidate messages (PROJECTED):**
  - producer ≈ 17 min / 1.1M queries;
  - consumer 6.5 h (0.2 s provider latency) to 29 h (1 s);
  - at the 15 s timeout, ≈ 240 messages/h.
- **Burst of 100k in-app notifications (PROJECTED):**
  - each job is one INSERT (cheap);
  - the cost is on the producer: an unindexed JSON dedupe scan of the recipient's history plus a cache write per alert (P87-009, P89-PERF-013);
  - it cannot start while `communications` has a backlog.
- **Amplification:**
  - reliability sweep is O(backlog) every 5 min (P89-PERF-007);
  - once a backlog is older than 1 h, `uniqueFor` 3600 expires and duplicate jobs follow;
  - circuit-pause churn;
  - no proactive provider throttle.
- **`default` queue:**
  - Filament exports (≈ 102 jobs per 10k-row export) and the export-completion notification;
  - `VerifyEmailChange` (unencrypted signed URL, P89-SEC-002);
  - it runs only when every higher queue is idle (P89-PERF-017).
- **Background worker:** embeddings (near 300 s) and LLM calls (60 s) on one process block `integrations` (P89-PERF-025).

### D. Scheduler

- 17 tasks, all `withoutOverlapping` + `onOneServer`.
- `dispatch-alerts` (hourly, foreground, unbounded `->get()` calls) is **likely to exhaust memory at 100k** (585 MB measured vs 256M). A PHP fatal skips every later check in that run (P89-PERF-004).
- `intelligence:refresh`:
  - 414 s cold at 520 open requisitions (≈ 0.8 s each);
  - PROJECTED ≈ 35 min at 2,600 and ≈ 70 min at 5,200, so it **exceeds its hourly cadence** (P89-PERF-005).
- **Benchmark of one requisition:** a Risk Radar scan costs 1.9 s / 123 queries at 100k.
- `outcomes:evaluate` rescans all historical hires and offers nightly (P89-PERF-014).
- Foreground tasks due in the same minute run in sequence, so a slow `dispatch-alerts` delays `reliability:sweep` and `queue:health-check`.

### E. Cache

- The `database` store holds:
  - metrics;
  - rate limiters;
  - step-up codes;
  - circuit breaker;
  - heartbeat;
  - dedupe keys;
  - scheduler mutexes;
  - unique-job locks.
- Expired rows are removed only when read again, so the table grows without bound (P89-PERF-016).
- **No stampede protection** for metrics. Concurrent misses recompute; results stay correct.
- **No cross-user leakage:** the metric key includes the visible-set fingerprint (`phase-8-9-security-review.md` §4).
- The runbook's `optimize:clear` flushes the whole cache table: lockouts, step-up codes, circuit state, heartbeat, dedupe keys (P89-OPS-004).

### F. Search

- `LIKE '%x%'` on code, name, mobile and email, plus source name, in:
  - the table search;
  - global search;
  - the command palette;
  - pickers.
  
  It is a full scan in every case (P89-PERF-003).
- The normalized identity columns are indexed but used only for duplicate detection.
- No FULLTEXT index.
- Name search (CHRO): **149 ms at 100k → 1.16 s at 500k → 2.2 s at 1M**. Manager name search: **0.6 s → 4.0 s → 8.0 s**.
- **Thresholds for D8.9-015** (no engine introduced):
  - exact / prefix lookups on indexed columns serve email, mobile and code at any tier;
  - substring name search passes ~1 s at about 500k candidates.

### G. Analytics

- **Semantics are sound.** No metric definition is questioned or changed.
- **The problem is live computation over full fact tables.** All governed metrics, organisation-wide, cold:
  - 3.4 s this month / **53.6 s 365 days** at 100k;
  - **26 s for this month alone** at 500k; the 365-day set **fails** with MySQL 1390;
  - **83 s for this month** at 1M.
- Slowest definitions:
  - `pipeline.time_in_stage`: 31.9 s at 100k; offset-paginated chunks are quadratic and did not complete at 1M (P89-PERF-029);
  - `sla.leg_compliance`: 11.8 s → 181 s → 880 s / 446 MB;
  - `pipeline.funnel`: 2.1 s → 19.7 s → 35.7 s.
- **Three definitions fail outright at scale:**
  - `offer_to_join`, too many placeholders (P89-PERF-027);
  - `time_to_hire` and `sla.leg_compliance`, memory above 256 MB (P89-PERF-028);
  - `time_in_stage`, quadratic (P89-PERF-029).
  
  Each can be fixed without changing what the metric means (ED-13).
- Manager scope: under 0.6 s at 100k but 15–27 s at 500k–1M. The scope narrows results, not scans.
- The 600 s cache is per viewer fingerprint with no warm-up (P89-PERF-001; D8.9-016 for materialization).

### H. Files

- Local `storage-data` volume only; `s3` is defined but unused.
- No orphan cleanup, OCR or malware scanning.
- Export files are never deleted (expiry is deferred with SEC-88-02).
- Growth ≈ 1 TB at 1M candidates (PF-88-07, PROJECTED).
- Entrypoint `chown -R` runs over the whole volume on every container start (P89-OPS-008).
- The Docker build context includes `storage/app` (P89-SEC-005).

### I. Automation

- Executions are created **synchronously in the request**, one per in-scope active rule per event. Conditions and the daily cap apply only at run time.
- 10k events × 10 org-scope rules (PROJECTED):
  - 100k executions, 1.4–4.2 h on one worker;
  - only 5,000 act;
  - about 95k end Skipped **and are never pruned** (P89-PERF-008, P89-DQ-014).
- The sweep orders every matching row by a correlated `max(id)` subquery (P89-PERF-009).
- The daily-limit count scans the rule's whole history (index gap on `started_at`).
- Loop prevention sees only synchronous chains. Asynchronous loops (`communication.failed` → send → fail) are bounded only by the caps.

### J. Communication

- **Queue time:** 11 queries / 10 ms per message.
- **Send time:** guard re-checks consent and context but not the sender's access (P89-SEC-011).
- **Duplicate protection is strong:** unique key, unique job, locked claim, Sending never resent.
- Webhooks are processed synchronously: 2–3 callbacks per message, capped at 600/min per IP. `communication_webhook_events` is never pruned.
- Throughput is bounded by one worker × provider latency (P89-PERF-006).

### K. Concurrency

See §5.
- **4 High data-integrity races:** incentives ×2, joining, offer terms.
- **7 Medium.**
- **No MySQL concurrency test exists.** The suite runs on SQLite (P89-OPS-013).

### L. Observability

- **Request id:** present on requests, commands and jobs, and in audit rows.
- **Logs:** plain text, `single` channel never rotated, debug level.
- **Not answerable today:** which job / queue processed a successful action, or whether a generic job eventually succeeded.
- **Tooling:** no APM, error tracker or metrics endpoint.
- **`/up`** does not check the database (P89-OPS-006).

### M. Failure recovery

Covered in detail in `phase-8-9-operational-readiness.md` §2.
- **Database outage is a total outage:** sessions, cache and queue all live in MySQL.
- **The `app` container has no restart policy.**
- **Alerting depends on the components it monitors** (P89-OPS-002).

### N. Deployment

- Migrations run on app container start; workers may start before they finish.
- Image tag is unversioned; there is no health check.
- `stop_grace_period` is set on workers but not on `app`.
- Production topology is unverified (P89-OPS-005, 011).

### O. Backup

**No backup mechanism exists in the repository** for the database or files. The runbook says only "Back up the database" (P89-OPS-001; D8.9-009).

### P. Disaster recovery

None: no DR plan, restore procedure or restore test. **No RTO/RPO is defined. Discovery does not invent one** (D8.9-007, 008, 010, 028).

### Q. Capacity planning

- No capacity review or headroom target exists.
- Model and benchmark plan: `phase-8-9-capacity-model.md` (D8.9-001, 002, 012).

### R. Operational readiness

- **Runbooks:** only `queue-operations.md`. Missing:
  - database outage;
  - restore;
  - DR;
  - disk full;
  - log rotation;
  - key rotation;
  - TLS / proxy;
  - deploy rollback (P89-OPS-010).
- **Support:** no on-call or severity model (D8.9-021, 025).

### S. Cost and efficiency

- **The database does everything:** cache, session and queue writes compete with OLTP traffic.
- **Repeated work:**
  - intelligence refresh recomputes cold every 6 h per requisition;
  - talent signals cost ~6 queries per application even when nothing is stale;
  - `send-reminders` re-checks each interview about 24× a day;
  - `VectorSearch` loads every chunk and embedding per RAG query (P89-PERF-018).
- **AI usage:**
  - budgets exist (8.x);
  - LLM calls carry a 60 s timeout with no circuit breaker (P83-009, still open, future AI phase).

### T. Security under load

See `phase-8-9-security-review.md`.
- **New findings:** 0 Critical, 1 High (P89-SEC-001), 5 Medium, 5 Low, 1 Informational.
- **No metric-cache leakage.**
- Hierarchy scoping holds under bulk AI operations.

---

## 5. Concurrency scenarios

Severity: H = double financial effect, terms changed after release, or loss of authority; M = duplicates, lost updates or broken invariants; L = self-healing, or the race ends in an error.

| # | Scenario | Protection today | Gap | Sev | Finding |
|---|---|---|---|---|---|
| 1 | Two recruiters edit one candidate | none (`EditRecord` blind update) | silent lost update, on every edit page | M | P89-DQ-008 |
| 2 | Same application moved twice | transaction only | no row lock; stale checks; backward moves; double reject cascade | M | P89-DQ-005 |
| 3 | Offer release vs acceptance | `moveTo` locks the offer ✔ | `releaseRevision` locks only the revision; Draft edit-save racing release | **H** | P89-DQ-004 |
| 3b | Two offers created at once | none | duplicate open offers | M | P89-DQ-006 |
| 4 | Joined vs Dropout / double Joined | unique joining per application | no lock; `guardActive` reads a stale copy; two incentive periods | **H** | P89-DQ-003 |
| 4b | Incentive recalculation vs approval | unique (rule, app, period) | stale status reverts Approved; duplicate top-ups | **H** | P89-DQ-002 |
| 4c | Double payment / reversal | none | two payment rows; two reversal adjustments | **H** | P89-DQ-001 |
| 5 | Slot booking | slot row lock ✔ | cancel / reschedule not idempotent; `booked_count` drift; cross-slot double booking (SEC-88-20, already deferred) | M | P89-DQ-007 |
| 6 | Employee conversion | candidate lock + unique ✔ | safe while top-level | L | — |
| 7 | Concurrent CHRO suspension | per-target lock | write skew leaves zero CHROs | M | P89-SEC-004 |
| 8 | Move to requisition / reassign | unique pair | guards outside the transaction | M | P89-DQ-009 |
| 9 | Automation cancel vs run | locked claim ✔ | `cancel()` unlocked; `finish()` overwrites; limits count only completed runs | M | P89-DQ-010 |
| 10 | AI approval | atomic claim ✔ | tool writes unlocked (see 2) | L | — |
| 11 | Performance freeze vs UI recompute | unique per period | frozen month rewritten | L-M | P89-DQ-012 |
| 12 | Career-site double submit | throttle only | duplicate candidates and messages | M | P89-DQ-011 |
| 13 | Communications | unique key + claim ✔ | blocked-retry and 5-min bucket duplicates | L | P89-DQ-013 |
| 14 | Stale worker authorization | per-job reload of the user ✔ | Spatie permission map is stale for up to 1 h | M | P89-SEC-003 |
| 15 | Nested lock-then-`refresh()` | — | REPEATABLE READ snapshot hides concurrent commits | L-M | P89-DQ-013 |
| 16 | Lock-order inversion | — | offer↔application, interview↔application deadlocks | L (ends in an error) | P89-PERF-021 |

## 6. Audit growth

- `audit_logs` gets about one row per audited write, with full PII diffs (SEC-88-05, accepted B).
- PROJECTED: 10M+ rows at 1M candidates (PF-88-06).
- **Indexes:** the list sorts on an unindexed `created_at`; filters on `action` and `actor_kind` are unindexed; the date filter uses `whereDate`.
- **BENCHMARK** (filter by action): 22 ms at 100k → 98 ms at 500k, with only 89k audit rows. The benchmark data was created by raw inserts, so it holds **far fewer audit rows than real use would**; real growth is steeper.
- **Archival / partitioning:** an engineering choice (D8.9-024).
- **Retention:** remains Legal's decision and stays **deferred with SEC-88-02**. Nothing is pruned or proposed for deletion here.

## 7. Data growth

| Table | Growth driver | Pruned? | Note |
|---|---|---|---|
| candidate_stage_histories | ~2.7 rows per application | no | fastest-growing fact table; metrics scan it |
| candidate_timeline_events | every lifecycle event | no | indexed per candidate / application ✔ |
| audit_logs | every audited write | no | retention deferred (SEC-88-02) |
| notifications | alerts, ~1 per situation per recipient per day | no | JSON dedupe cost grows with history (P87-009) |
| candidate_communications, communication_webhook_events | messages, 2–3 callbacks each | no | |
| automation_executions / action_executions | executions per event × rule | only `conditions_passed=false` after 90 days | limit-skipped rows are never pruned (P89-DQ-014) |
| hiring_health_snapshots, intelligence_evidence | up to 4 snapshots per open requisition per day, ≈ 21 evidence rows each (≈ 66 KB per open-requisition-day) | no (time series by design) | ≈ 24 GB/year per 1,000 open requisitions, PROJECTED (P89-PERF-026) |
| talent_signal_snapshots, hiring_memory_records, hiring_outcomes | per application / rejection / hire | no | |
| ai_* (7 tables) | per AI interaction | no | `ai:redact-history` is dry-run only |
| cache | every key, including one-off dedupe keys | **read-time only** | ED-08 housekeeping (technical, not retention) |
| job_batches, exports (+ files), password_reset_tokens | exports, resets | no | ED-08 |
| sessions | per session; candidate and staff share the table | lottery GC | P89-SEC-007 |
| storage-data volume | resumes, documents, offer letters, exports | no | ≈ 1 TB at 1M (PROJECTED) |
| storage/logs/laravel.log | every log line at debug | **never rotated** | P89-OPS-006 |

Per-tier row projections are in `phase-8-9-capacity-model.md` §3.

**Retention, erasure and anonymization remain deferred** to the dedicated data-governance phase. This table records growth; it does not propose deleting anything with legal meaning.

## 8. Data-quality findings (P89-DQ)

Fields: ID, severity, component, evidence (verified), impact, current control, recommended direction, dependencies.

| ID | Sev | Component | Evidence | Impact | Current control | Direction | Impl. dep. | Decision dep. |
|---|---|---|---|---|---|---|---|---|
| **P89-DQ-001** | **High** | Incentive approvals / payments | `IncentiveApprovalService.php:142-172,227-258,282-302,309-330`: no `lockForUpdate` anywhere in the file; no one-payment rule (`2026_08_26_121212:26`) | double payment rows; double reversal; `releaseMatured` races manual decisions | transactions only | lock the calculation row and re-check status; conditional update; payment idempotency | small–medium | D8.9-029 (+ Finance) |
| **P89-DQ-002** | **High** | Incentive calculator | `RecruiterIncentiveCalculator.php:158-167,210-238,246-267,331-341` | stale recalculation reverts Approved or rewrites amounts; duplicate retroactive top-ups; wrong slab counts | unique (rule, app, period) | serialise per (rule, employee, period); lock and re-read the calculation | medium | D8.9-029 |
| **P89-DQ-003** | **High** | Joining transitions | `CandidateJoiningService.php:62-130,214-219`: no lock; `guardActive` checks an in-memory copy | incentive for a non-hire; two incentive periods for one join | unique joining per application | lock joining + application; anchor the period to the first Joined | small | D8.9-029 |
| **P89-DQ-004** | **High** | Offer terms | `OfferService.php:286-304` (`releaseRevision` locks only the revision); Draft edit path `Offer.php:60-80` + `EditRecord` | revised terms or a new letter on an accepted offer; terms edited after the letter (and its SHA-256) was issued | `moveTo` lock | lock the offer and re-check; route term edits through a locked service | small | D8.9-029 |
| P89-DQ-005 | Medium | Stage transitions | `StageTransitionService.php:49-128,193-280,307-388` (0 locks) | lost updates; backward moves; double reject cascade and double events | transaction | lock the application row and re-check inside | small–medium | D8.9-029 |
| P89-DQ-006 | Medium | Offer creation | `OfferService.php:109-137` (`offerBlocker` outside the transaction) | two open offers per application | acceptance withdraws the extra one | lock the application; re-check inside | small | — |
| P89-DQ-007 | Medium | Slot bookings | `InterviewSchedulingService.php:258-340,424-433` | double cancel decrements `booked_count` twice (overbooking); double reschedule | slot lock | lock the booking; recompute the counter | small | with E-09 / SEC-88-20 (deferred) |
| P89-DQ-008 | Medium | All Filament edit pages | `EditRecord.php:281-286` blind update; no version column | silent lost updates | none | optimistic check (`updated_at` at mount) or dirty-field save | medium | Product (UX) |
| P89-DQ-009 | Medium | Move to requisition / reassignment | `ApplicationAssignmentService.php:43-139` | guards bypassed by concurrent work; raw `QueryException` | unique pair | lock + re-check; friendly error | small | — |
| P89-DQ-010 | Medium | Automation cancel / limits | `AutomationEngine.php:219-235,640-674,696-704` | a cancelled run still acts; limits exceeded across workers | locked claim | conditional cancel; `finish` refuses terminal; locked limit check | small | — |
| P89-DQ-011 | Medium | Career-site apply | `CareerApplicationService.php:93-97`; email/mobile not unique | duplicate candidates and messages on double submit | throttle | keyed lock or submission token | small | — |
| P89-DQ-012 | Low-Med | Performance snapshots | `PerformanceEngine.php:91-122` (frozen check before any lock) | frozen month rewritten | unique per period | conditional update `WHERE frozen_at IS NULL` | small | — |
| P89-DQ-013 | Low | Smaller races | interview round `count()+1` (`InterviewService.php:72-74`); feedback after completion; template version `max+1`; blocked-retry / 5-min bucket duplicates (`CommunicationService.php:72-80,343`); requisition approval unlocked; automation `retry_count`; escalation cap snapshot (`EscalationService.php:84-91`); nested lock-then-`refresh()` (`InterviewService.php:316-320`, `OfferService.php:158-159`) | cosmetic, or ends in an error | various | as listed in the concurrency review | small | — |
| P89-DQ-014 | Low | Automation history | `CleanupAutomation.php:26` deletes only `conditions_passed=false`; limit-skipped rows leave it null | storm leftovers accumulate forever | none | include limit-skipped rows in cleanup (technical, not retention) | trivial | — |
| P89-DQ-015 | Low | Talent signals | `TalentSignalService.php:99-105` `orderBy('id')->limit(200)` | active applications beyond the first 200 in a requisition are never refreshed by the scheduler | on-demand refresh | page through, or prioritise stale ones | small | — |
| P89-DQ-016 | Low | Development storage | 231 offer-letter PDFs in `storage/app/private/offer-letters` written by tests (DQ-88-16, up from ~129) | test artefacts in a tree that Docker builds copy (P89-SEC-005) | none | fake the disk in those tests | trivial | — |
| P89-DQ-017 | Low | "Selected but no offer" alert | `DispatchRecruitmentAlerts.php:178-183` has no `status` filter | applications at stage Selected but rejected / on hold / withdrawn keep alerting daily (impact inferred) | per-day dedupe | add the active-status filter (alert behaviour, not a metric) | trivial | Product confirms intent |
| P89-DQ-018 | Low | Backlog record | `docs/backlog.md` has no P88 section; 8 completed items still Open; 9 items never tracked (§10) | decisions made on a stale record | none | reconcile `backlog.md` from §10 when implementation is approved | documentation | — |

## 9. Finding index

| Family | IDs | Where |
|---|---|---|
| P89-PERF | 001 – 029 | `phase-8-9-performance.md` §8 |
| P89-SEC | 001 – 012 | `phase-8-9-security-review.md` §2 |
| P89-DQ | 001 – 018 | §8 above |
| P89-OPS | 001 – 015 | `phase-8-9-operational-readiness.md` §7 |

---

## 10. Backlog reconciliation (Phases 7 – 8.8)

**Scope.** 336 item IDs from:
- `docs/backlog.md`;
- the Phase 7 documents;
- every Phase 8.1 – 8.8 document;
- the 8.5 / 8.6 / 8.7 finding families.

**Rules.**
- **Nothing is removed.**
- Every item keeps its recorded decision.
- "Moved to 8.9" items are mapped to a P89 finding below.
- Items with a policy half (retention) and a capacity half keep the policy half **deferred** and only the capacity half in 8.9.

**Counts:**

| Classification | Count |
|---|---|
| completed | 148 |
| still open | 56 |
| deferred | 43 |
| moved to 8.9 | 32 |
| accepted | 29 |
| duplicate | 24 |
| superseded | 2 |
| unknown | 2 |
| **total** | **336** |

| Phase | comp | def | acc | 8.9 | sup | dup | open | unk | total |
|---|---|---|---|---|---|---|---|---|---|
| 7 | 5 | 0 | 2 | 2 | 0 | 0 | 3 | 0 | 12 |
| 8.1 | 1 | 2 | 2 | 0 | 0 | 0 | 1 | 0 | 6 |
| 8.2 | 1 | 0 | 6 | 1 | 0 | 0 | 2 | 0 | 10 |
| 8.3 | 2 | 0 | 2 | 1 | 1 | 0 | 4 | 0 | 10 |
| 8.4 | 2 | 0 | 2 | 0 | 0 | 0 | 8 | 0 | 12 |
| 8.5 | 29 | 1 | 2 | 5 | 0 | 0 | 7 | 0 | 44 |
| 8.6 | 41 | 3 | 2 | 1 | 1 | 9 | 7 | 2 | 66 |
| 8.7 | 37 | 2 | 3 | 8 | 0 | 5 | 3 | 0 | 58 |
| 8.8 | 27 | 35 | 7 | 14 | 0 | 6 | 20 | 0 | 109 |
| Phase 8 discovery bullets | 3 | 0 | 1 | 0 | 0 | 4 | 1 | 0 | 9 |

### 10.1 Moved to 8.9 (32) → Phase 8.9 finding

| Item | Title | 8.9 finding |
|---|---|---|
| TD-002 | Dev seeder default password | P89-OPS-011 |
| P7-READY | Unchecked production checklist | P89-OPS-011 |
| P82-BACKLOG-006 | First evaluation after a big backfill | P89-PERF-014 |
| P83-BACKLOG-004 | `observed_at` not indexed | P89-PERF-011 |
| P85-BACKLOG-003 | Metric cache invalidated by expiry only | P89-PERF-001 |
| P85-BACKLOG-006 | SLA sweep loads every breaching application | P89-PERF-004 |
| P85-BACKLOG-007 | Stage-entry fact table | P89-PERF-001 (D8.9-016) |
| 8.5 PF-10 | `effectiveAmount()` N+1 (untracked until now) | P89-PERF-022 |
| 8.5 PF-11 | Organisation-wide dashboard cost | P89-PERF-015 |
| P86-BACKLOG-010 | Settings-aware metric cache key | P89-PERF-001 |
| P87-BACKLOG-004 | Risk Radar / Hiring Health cost at scale | P89-PERF-005 |
| P87-BACKLOG-005 | Redis queue / horizontal workers | P89-PERF-006, 016 (D8.9-014, 018) |
| P87-BACKLOG-006 | Email platform alerts to operations | P89-OPS-002 (D8.9-020) |
| P87-BACKLOG-009 | JSON dedupe lookup on notifications | P89-PERF-013 |
| P87-BACKLOG-010 | Outcome learning loads full history | P89-PERF-014 |
| P87-BACKLOG-011 | Large document embedding near 300 s | P89-PERF-025 |
| 8.7-KL-1 | No multi-process load test | P89-OPS-013 |
| 8.7-KL-2 | Shared cache store required | P89-PERF-016 |
| PF-88-01 | Hierarchy-scoped candidate list O(total) | P89-PERF-002 |
| PF-88-02 | `LIKE '%x%'` search | P89-PERF-003 |
| PF-88-03 | Exports: cap ✔, disk growth, `default` queue | P89-PERF-017 (file expiry stays with SEC-88-02) |
| PF-88-04 | Interviewer import synchronous | P89-PERF-024 (security twin SEC-88-25 stays C) |
| PF-88-05 | Talent-pool bulk add uncapped | P89-PERF-024 |
| PF-88-06 | Audit growth | P89-PERF-013 (D8.9-024; retention deferred) |
| PF-88-07 | Document storage | P89-OPS-009 / D8.9-017 (retention deferred) |
| PF-88-08 | Shared sessions table | P89-PERF-016, P89-SEC-007 |
| PF-88-09 | Portal-upload listener synchronous | P89-PERF-024 (= E-14) |
| PF-88-10 | Career index / feed unthrottled | P89-SEC-010 (security twin SEC-88-21 stays C) |
| PF-88-11 | Export scope id lists in job payloads | P89-PERF-017, P89-SEC-012 |
| PF-88-12 | Future retention / anonymization runs | design input only; build waits for the retention phase |
| P88-BACKLOG-006 | PF-88-01 … 12 umbrella | as above |
| 8.8-U3 | 17-day-old job on the dev `default` queue | P89-OPS-014 |

### 10.2 Never tracked: now given a home (not reclassified, only made visible)

| Item | Origin | Status | Now tracked as |
|---|---|---|---|
| Gemini key rotation (Phase 7 production blocker) | `phase-7-freeze-and-commit-review.md:104` | still open | P89-OPS-015 |
| 8.5 PF-10 `effectiveAmount()` N+1 | 8.5 discovery | moved to 8.9 | P89-PERF-022 |
| 8.5 PF-11 organisation-wide dashboard | 8.5 discovery | moved to 8.9 | P89-PERF-015 |
| CTC visible in the offer edit form without `compensation.view` | 8.5 security review | still open | backlog (security; not reclassified) |
| 8.6 DQ-4 interview round names free text | 8.6 discovery | still open | backlog |
| 8.6 AG-11 no audit export, no user agent | 8.6 discovery | still open | backlog |
| 8.6 AG-13 notification reroute only logged | 8.6 discovery | still open | backlog |
| 8.4 unknown-email staff sign-in failures logged, not audited | 8.4 security review | still open | backlog |
| PII not encrypted at rest | Phase 8 bullets / 8.8 discovery | still open | backlog (Security decision) |
| Prompt-injection surface via candidate-editable fields | 8.8 discovery §19 | still open (Low) | backlog (future AI phase) |
| `CandidateDocument` not Auditable (E-06 residual) | 8.8 E-06 | still open | backlog |

### 10.3 Full reconciliation table

Status source abbreviations:

| Abbreviation | Document |
|---|---|
| BL | `backlog.md` |
| DI8x | `phase-8-x-discovery` |
| SR8x | security review |
| PF8x | performance |
| DR8x | decision record |
| IM8x | implementation |
| F8x | freeze |
| RT88 | retention decision |
| EX88 | export governance decision |

| ID | Title | Latest status source | Evidence | Classification |
|---|---|---|---|---|
| **Phase 7** | | | | |
| P7-BACKLOG-001 | Synonym-aware skill matching | DI88:347 | `IntelligenceText.php:9` | still open |
| P7-BACKLOG-002 | Stronger fairness filtering | DI88:348 | `IntelligenceAiService.php:63` | still open |
| P7-BACKLOG-003 | SLA health beyond 200 candidates | MG85:112 DF-12 | no `take(200)` (`RecruitmentAnalyticsService.php:943-963`) | completed (8.5; backlog.md stale) |
| P7-BACKLOG-004 | Insufficient history | BL:38 | — | accepted |
| P7-BACKLOG-005 | Hiring Memory AI retry / failure audit | DR87:259 | `SummarizeHiringMemoryJob.php:66` | completed (8.7) |
| P7-BACKLOG-006 | Old Copilot tools sent names | BL:52 | — | completed (8.1) |
| P7-BACKLOG-007 | Queued AI actor only in payload | D8.7-015 | `AuditLog.php:25,89` | completed (8.7) |
| TD-001 | Visibility-rule copies | BL:69 | copies remain (`EmployeeReferral.php:128`) | accepted |
| TD-002 | Dev seeder default password | BL:76 | `AdminUserSeeder.php:44` | moved to 8.9 |
| TD-003 | Provider error bodies logged | BL:81 | — | completed (8.1) |
| P7-FRZ-1 | Gemini key rotation | P7FR:104 | — | still open (untracked → P89-OPS-015) |
| P7-READY | Production checklist | P7PR:28-53; F88:185-192 | `.env.example:3,5` | moved to 8.9 |
| **Phase 8.1** | | | | |
| P81-BACKLOG-001 | Names typed by users | DI88:354 | — | accepted |
| P81-BACKLOG-002 | Pre-8.1 AI history at rest | RT88 R-11 | `AiRedactHistoryCommand.php:30-31` | deferred (retention phase) |
| P81-BACKLOG-003 | Knowledge-base per-document access / retention | SEC-88-16 C | `AiDocumentForm.php:34` | deferred |
| P81-BACKLOG-004 | `ai:test-provider` bypass | BL:104 | — | accepted |
| P81-BACKLOG-005 | `find_inactive_recruiters` scope | MG85:102 DF-2 | `FindInactiveRecruitersTool.php:60` | completed (8.5; backlog.md stale) |
| P81-BACKLOG-006 | Recruiter performance via Copilot (policy) | DI88:358 | — | still open |
| **Phase 8.2** | | | | |
| P82-BACKLOG-001 – 004 | Post-hire data; no pre-8.2 retention history; status-observation confidence; 90-day learning history | DI88:359 | — | accepted ×4 |
| P82-BACKLOG-005 | Skill labels rebuilt from keys | DI88:360 | `OutcomeLearningService.php:198` | still open |
| P82-BACKLOG-006 | First evaluation after a big backfill | DI88:360 | — | moved to 8.9 |
| P82-BACKLOG-007 | Insight AI explanation retry | DR87:259 | `SummarizeOutcomeInsightJob.php:66` | completed (8.7) |
| P82-BACKLOG-008 | Talent Signal not marked stale on insight accept | DI88:360 | `OutcomeLearningService.php:99` | still open |
| P82-BACKLOG-009, 010 | Insights organisation-wide; minimal separation record | DI88:359 | — | accepted ×2 |
| **Phase 8.3** | | | | |
| P83-BACKLOG-001 | Separation did not revoke access | DI88:362 | `StaffAccessService.php:72,88,142` | completed (8.4) |
| P83-BACKLOG-002 | Pre-8.3 lifecycle repair plan | DI88:363 | — | still open (no silent repair) |
| P83-BACKLOG-003 | Conflicting metric definitions | 8.5 registry | leftover = P87-001 | superseded |
| P83-BACKLOG-004 | `observed_at` not indexed | DI88:364 | no index | moved to 8.9 |
| P83-BACKLOG-005 | Accepted offers cannot be revised | DI88:365 | — | still open (product) |
| P83-BACKLOG-006 | Feedback draft / lock | DI88:366 | — | accepted |
| P83-BACKLOG-007 | Two stage models | DI88:364 | `CandidateStage` + configured stages | still open |
| P83-BACKLOG-008 | Template re-apply `saveQuietly` | DI88:366 | — | accepted |
| P83-BACKLOG-009 | AI reliability (429, RAG timeout, budgets) | DI88:364 | no 429 handling in providers | still open (future AI phase) |
| P83-BACKLOG-010 | Notification dedupe race | DI88:367 | `NotificationDispatchService.php:53` | completed (8.7) |
| **Phase 8.4** | | | | |
| P84-BACKLOG-001 | No API tokens (constraint) | SR88:447 | `IdentityArchitectureTest.php:77-80` | accepted |
| P84-BACKLOG-002 | Immediate session deletion needs the DB driver | DI88:369 | `SessionRevocationService.php:21` | still open |
| P84-BACKLOG-003 – 008 | Email change needs sign-in; handoff bulk move; employment episodes; delegated AI approval; MFA redirect at page load; offline password checks | DI88:370-371 | — | still open ×6 |
| P84-BACKLOG-009 | Master data not audited | DI88:372 | `Department.php:18` | completed (8.6) |
| P84-BACKLOG-010 | Metric catalogue | BL:275 | `MetricService.php` | completed (8.5) |
| P84-BACKLOG-011 | Role DNA with unreachable provider | MG85:201 | — | accepted |
| 8.4-RR-1 | Unknown-email sign-in failures not audited | SR84:138 | `RecordStaffAuthEvents.php:35` | still open (untracked) |
| **Phase 8.5** | | | | |
| P85-BACKLOG-001 | No-show / dropout dated by `updated_at` | DI88:374 | no `status_changed_at` | still open |
| P85-BACKLOG-002 | Attribution not date-effective | IM86:179 | — | still open |
| P85-BACKLOG-003 | Metric cache invalidated by expiry only | BL:299 | `config/metrics.php:28` | moved to 8.9 |
| P85-BACKLOG-004 | Incentive "no target" priced as 0% | — | `RecruiterIncentiveCalculator.php:174` | still open |
| P85-BACKLOG-005 | Joining risk colours | DI88:374 | `CandidateJoining.php:76-82` | still open |
| P85-BACKLOG-006 | SLA sweep hydration | BL:314 | `RecruitmentSlaService.php:135` | moved to 8.9 |
| P85-BACKLOG-007 | Stage-entry fact table | BL:319 | no such table | moved to 8.9 |
| P85-BACKLOG-008, 009 | Outcome filters use live attributes; plan rate not narrowed | BL:324,329 | — | still open ×2 |
| P85-BACKLOG-010 | Browsable metric catalogue page | BL:334 | — | deferred (8.12+) |
| 8.5 PF-1 – PF-7 | N+1 / hydration / indexes | PF85:84-91 | sweep 24 queries | completed ×7 |
| 8.5 PF-8, PF-9, PF-12 | Funnel; Pulse; Hiring Health caps | PF85 / DE85 | — | completed ×3 |
| 8.5 PF-10 | `effectiveAmount()` N+1 | — | `RecruiterIncentiveCalculation.php:80` | moved to 8.9 (untracked) |
| 8.5 PF-11 | Organisation-wide dashboard > 15 s | PF85:106 | — | moved to 8.9 |
| 8.5 PF-13 | Outcome report is cheap | — | — | accepted (not a defect) |
| 8.5 DF-1 – DF-14 | Metric definition fixes | MG85:101-114 | — | completed ×14 |
| 8.5 SEC-1 – SEC-5 | Widget gates etc. | SR85 | — | completed ×5 |
| 8.5 SEC-6 | Alerts organisation-wide by design | SR85:46 | — | accepted |
| 8.5-RR-1 | CTC in offer edit form | SR85:79 | `OfferForm.php` (no gate) | still open (untracked) |
| **Phase 8.6** | | | | |
| P86-BACKLOG-001 | FK action hardening | D8.6-006 | 18 migrations | deferred |
| P86-BACKLOG-002 | Skills taxonomy | DI88:375 | — | still open |
| P86-BACKLOG-003 | Scheduler / job request id | D8.7-014 | `AppServiceProvider.php:274` | superseded |
| P86-BACKLOG-004 | Audit immutability and retention | D8.8-031 BLOCKED | — | deferred (retention phase) |
| P86-BACKLOG-005 | Configuration maker-checker | DI88:375 | — | still open |
| P86-BACKLOG-006 | Employee org history | = P85-002 | — | duplicate |
| P86-BACKLOG-007 | Deploy the SEC-1 hotfix + joining `create()` fix | F88:176-181 | `2fab3fd` lacks `create()` | still open (release action, D8.9-027) |
| P86-BACKLOG-008 | Stage deactivation not re-validated | DI88:375 | `StageConfigurationService.php:148-153` | still open |
| P86-BACKLOG-009 | Offer-letter storage and retention | R-9 BLOCKED | — | deferred (retention phase) |
| P86-BACKLOG-010 | Settings-aware metric cache key | BL:380 | `MetricService.php:51-63` | moved to 8.9 |
| 8.6 PF-1 | Metric cache not keyed on settings | = P86-010 | — | duplicate |
| 8.6 PF-2, PF-3, PF-5 | Re-apply chunks; import transaction; in-use checks | PFD86 / IM86 | — | completed ×3 |
| 8.6 PF-4 | Incentive re-price loop | never measured | — | unknown |
| 8.6 DQ-1, 2, 5 – 10 | Data-quality fixes | IM86 | — | completed ×8 |
| 8.6 DQ-3 | Skills free text | = P86-002 | — | duplicate |
| 8.6 DQ-4 | Interview round names free text | — | 3 migrations | still open (untracked) |
| AG-1 – AG-7, AG-9, AG-10 | Audit coverage gaps | IM86; D8.7-014 | — | completed ×9 |
| AG-8 | DB cascades unaudited | = P86-001 | — | duplicate |
| AG-11 | Audit export / user agent | DR86:270 | `AuditLog.php:25` | still open (untracked) |
| AG-12 | Audit immutability / retention | = P86-004 | — | duplicate |
| AG-13 | Notification reroute only logged | — | `NotificationDispatchService.php:101` | still open (untracked) |
| HR-1, 2, 4 – 11, 13 | Historical reproducibility | IM86 §1 | — | completed ×11 |
| HR-3 | Time to hire, pre-snapshot hires | IM86:172-176 | — | accepted |
| HR-12 | Hiring Health thresholds not recorded | — | `ConfigurationFingerprint.php:36-57` | unknown |
| HR-14 | Transfer applicability | = P85-002 | — | duplicate |
| VG-11, DR-15 | Config version bump; blank labels | IM86 | — | completed ×2 |
| 8.6 SEC-1 | Filament delete bypass | = P86-007 | — | duplicate |
| 8.6 SEC-2 – 6, 8 | Governance security | SR86 | — | completed ×6 |
| 8.6 SEC-7 | Audit mutable / global | = P86-004 | — | duplicate |
| SEC-86-I-01 | Manual joining `create()` | fixed on branch, missing on hotfix | `CandidateJoiningPolicy.php:31` | duplicate (P86-007) |
| SEC-86-I-02, I-03 | Automation retry; read-only policies | SR86 | — | completed ×2 |
| 8.6-KL | Known limitations | IM86:168-182 | — | accepted |
| **Phase 8.7** | | | | |
| P87-BACKLOG-001 | Daily metrics → governed offer definition | F87:150 | `RecruiterDailyMetricsService.php:81` | still open (product + payroll) |
| P87-BACKLOG-002 | Automation priority groups | DR87:130 | — | still open |
| P87-BACKLOG-003 | `{{links.scheduling}}` | D8.8-023 BLOCKED | `TemplateRenderer.php:143` | deferred |
| P87-BACKLOG-004, 005, 006, 009, 010, 011 | Radar cost; Redis; alert email; JSON dedupe; learning history; embedding | PF87 | — | moved to 8.9 ×6 |
| P87-BACKLOG-007 | Remove unused AI communication code | SR87:26 | `app/Mail/AiCopilotEmail.php` | still open |
| P87-BACKLOG-008 | Legal retention for `failed_jobs` / logs | R-13 BLOCKED | `routes/console.php:42` | deferred (retention phase) |
| PF-87-01, 02, 03, 05, 06, 07 | Scheduler / queue bottlenecks | PF87:59-65 | — | completed ×6 |
| PF-87-04, 08, 09 | = P87-009 / 010 / 011 | — | — | duplicate ×3 |
| PF-87-10 | `processDue` ceiling | D8.7-027 | — | accepted |
| DQ-87-01 – 06, 08 – 15, 18 | Data-quality fixes | F87:134-151 | — | completed ×15 |
| DQ-87-07 | Stored WhatsApp body differs | F87:140 | — | accepted |
| DQ-87-16, 17 | = P87-003 / P87-001 | — | — | duplicate ×2 |
| SEC-87-01 – 14, SEC-87-I-01, REL-87-I-02 | Asynchronous-path security | SR87 | `tests/Feature/Reliability/*` | completed ×16 |
| 8.7-KL-1, KL-2 | No load test; shared cache store | IM87:150-151 | — | moved to 8.9 ×2 |
| 8.7-KL-3 | No back-fill of 8.7 columns | IM87:149 | — | accepted |
| **Phase 8.8** | | | | |
| P88-BACKLOG-001 | SEC-88-02 retention / erasure / legal hold; export-file expiry | F88:168 | nothing exists | **deferred (data-governance / retention phase, not 8.9)** |
| P88-BACKLOG-002 | Export governance remainder (X-1, 2, 6, 7, 10) | DR88:59 BLOCKED | — | still open |
| P88-BACKLOG-003, 004 | Deferred Medium / Low umbrellas | SR88 | — | deferred (members keep their class) |
| P88-BACKLOG-005 | Engineering defaults not delivered | DR88:61 | — | still open |
| P88-BACKLOG-006 | PF-88-01 … 12 | F88:173 | — | moved to 8.9 |
| P88-BACKLOG-007 | Staff photos on the public disk | F88:174 | `EmployeeForm.php:83` | still open |
| SEC-88-01, 03, 04, 06, 08, 09, 11, 12, 13, 15, 17, 19, 24, 29 | Closed findings | SR88 | `tests/Feature/Security/SEC88*` | completed ×14 |
| SEC-88-02 | No retention / erasure | SR88:110 | — | **deferred (retention phase)** |
| SEC-88-05, 07, 14 | PII in audit; long-lived links; consent evidence | SR88 | — | **accepted (B)** ×3 |
| SEC-88-10, 16, 18, 20 – 23, 25 – 28 | Deferred (C) | SR88:116-134 | — | **deferred (C)** ×12 |
| SEC-88-30 | Positive controls | SR88:136 | — | accepted (not a defect) |
| PF-88-01 – 12 | Performance findings | F88:173 | — | moved to 8.9 ×12 |
| DQ-88-01 | Career auto-match | SEC-88-01 | — | completed |
| DQ-88-02 | Bookings never closed | D8.8-024 BLOCKED | — | deferred |
| DQ-88-03, 05, 08, 09, 11 | = SEC-88-20 / 18 / P81-003 / X-8 / SEC-88-18 | — | — | duplicate ×5 |
| DQ-88-04 | No duplicate merge | D8.8-013 BLOCKED | — | still open |
| DQ-88-06 | Orphaned files; `resume_path` unset | E-11 | — | still open |
| DQ-88-07 | Consent timestamps | SEC-88-14 B | — | accepted |
| DQ-88-10 | Import provenance | — | export half done | still open (import half) |
| DQ-88-12 – 16 | Events without listeners; `candidate_visible`; UTC times; time-of-day tests; test PDFs on disk | — | 231 PDFs | still open ×5 (DQ-88-16 → P89-DQ-016) |
| E-01, E-11, E-12 | Service-only mutations; file deletion; time-independent tests | F88:172 | — | still open ×3 |
| E-02, E-07, E-09, E-10 | Follow their deferred SEC findings | — | — | deferred ×4 |
| E-03, 04, 05, 06, 08 | Delivered | — | — | completed ×5 (E-06 residual untracked) |
| E-13 | Redact new audit rows | SEC-88-05 B | — | accepted |
| E-14 | = PF-88-09 | — | — | duplicate |
| X-1, 2, 6, 7, 10 | Export role split / restriction / reason / approval / rate limits | EX88:19 | — | still open ×5 |
| X-3, 4, 5, 9, 11, 13 | Delivered export controls | EX88 | `AppServiceProvider.php:148-164` | completed ×6 |
| X-8, X-12 | Export file expiry; AI uploads | EX88:17-18 | — | deferred ×2 |
| R-1 – R-13 | Retention questions | RT88:97-109 | — | deferred ×13 |
| R-14 | Freeze treatment of SEC-88-02 | RT88:9 | — | completed |
| 8.8-U1 | Prompt-injection surface | DI88:295 | — | still open (untracked) |
| 8.8-U2 | Known limitations | F88:155-161 | — | accepted |
| 8.8-U3 | Stale dev job | F88:162 | — | moved to 8.9 |
| **Phase 8 discovery bullets** | | | | |
| P8D-1, 4, 5 | Lifecycle bundle; panel access; upload type | — | — | completed ×3 |
| P8D-2, 3, 6, 7 | = AG-11 / P86-004 / X-1 / SEC-88-02 | — | — | duplicate ×4 |
| P8D-8 | PII not encrypted at rest | DI88:130 | — | still open (untracked) |
| P8D-9 | Multi-tenancy | — | — | accepted (non-goal) |

**Ambiguities recorded, not resolved:**
1. **`docs/backlog.md` is stale.** It still shows these completed items as Open: P7-003/005/007, P81-005, P82-007, P83-001/010, P84-009. It also has no P88 section (P89-DQ-018).
2. **E-13** is "awaiting approval" in P88-BACKLOG-005, but its parent SEC-88-05 is accepted (B). It is classed accepted.
3. **Unknowns:** 8.6 PF-4 (never measured) and HR-12 (thresholds probably covered by settings history; unconfirmed).
4. **Untracked note set:** "G2–G13 evidence notes" (DI87:715) were never published, so they cannot be reconciled.
5. **Release checklist:** SEC-88-10, SEC-88-27 and P86-007 keep their recorded class but belong on the deployment checklist (P89-OPS-011, 012).

---

## 11. Non-goals

Not done in this discovery, and not part of the 8.9 implementation scope unless separately approved:
- new features, AI capabilities, channels, metrics or dashboards;
- SSO, SCIM, tenancy, API expansion;
- incentive formula changes;
- Outcome Loop, Role DNA or Hiring Memory semantics;
- authentication redesign;
- historical data repair;
- retention-policy changes (SEC-88-02 stays deferred);
- search engines (Elasticsearch, OpenSearch, Meilisearch, Algolia);
- storage migration;
- new providers;
- invented SLOs, RTO, RPO or retention periods.

## 12. Completion gate

| Item | State |
|---|---|
| Baseline verified | ✔ |
| Areas A–T investigated | ✔ |
| Volumes labelled ACTUAL / BENCHMARK / PROJECTED | ✔ (production: unknown) |
| Findings: P89-PERF 29, P89-SEC 12, P89-DQ 18, P89-OPS 15 | ✔ |
| Decisions D8.9-001 … 031 | ✔ all proposed / BLOCKED, none approved |
| Backlog reconciled (336 items, nothing removed) | ✔ |
| Code / migrations / routes / indexes / queue / scheduler / workers / auth changed | **none** |
| Throwaway benchmark databases | dropped after measurement |
| Push / deploy / Phase 8.10 | **not done / not started** |

**Next action:** await explicit approval before Phase 8.9 implementation. AI recommends; humans decide.
