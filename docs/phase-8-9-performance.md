# Phase 8.9 Performance (Discovery)

**Status:** discovery only.
- No index, query, cache, queue, worker or scheduler change was made.
- Every measurement ran against throwaway databases (`hrms_p87_perf` → `hrms_p89_bench`), which were dropped afterwards.

**Labels:** ACTUAL / BENCHMARK / PROJECTED, as defined in `phase-8-9-discovery.md`.

**Nothing here is a service level.** SLOs are D8.9-004/005/006 and are not set by engineering.

## 1. Method

**Probe.** `p89_probe` runs the application's own code paths through `artisan tinker` against the benchmark database. It refuses any other database. It covers:
- Filament resource queries (`getEloquentQuery` + first page + count);
- `visibleTo` scopes;
- `CandidateDuplicateDetector`;
- `CandidateTimelineService`;
- `RecruitmentActionCenterService`;
- `MetricService` for every registered metric;
- `OutcomeEvaluator`;
- `HiringRiskRadar::scan`;
- `CandidatePortalService`.

**Measurement.**
- Each operation runs 3× (1× for multi-second analytics) with the cache flushed before each run.
- Median and p95 are recorded, along with query count and peak PHP memory.
- `EXPLAIN` is run on the slowest query.
- **Cold-cache numbers are the worst case.** Governed metrics are cached for 600 s per viewer-scope fingerprint.

**Viewers.**
- `chro`: `hierarchy.view-all`.
- `manager`: the manager with the largest subtree (30 employees).
- `recruiter`: a recruiter-role user on the busiest recruiter employee, created in the throwaway database.

**Scaling.**
- Each requisition slice (requisitions, applications, candidates, histories, interviews, feedback, offers, joinings, communications, timeline, documents, audit) is cloned with an id offset of k × 10⁷.
- `ANALYZE TABLE` runs after each tier.
- Hierarchy, users and settings are unchanged, so a manager's visible *set* stays the same while the table under it grows. That is exactly the case that exposes full scans.

**Environment.** Development workstation, MySQL 8.4 with default settings (buffer pool not tuned), PHP 8.5 CLI.
- Absolute numbers will differ in production.
- **Growth between tiers is the signal.**

**Important caveat.** The benchmark MySQL has the **default 128 MB buffer pool**. The data is about 0.3 GB at 100k and about 2.3 GB at 1M.
- Above 100k, scans become **disk-bound**, so absolute times at 500k and 1M are pessimistic; a tuned production server would be faster.
- Some findings do **not** depend on this. They are code-level and independent of hardware:
  - growth of full scans with total rows;
  - the placeholder failure (P89-PERF-027);
  - PHP memory peaks (P89-PERF-028);
  - quadratic chunking (P89-PERF-029).

## 2. Measured tiers (BENCHMARK)

**Population per tier:**

| | 100k | 500k | 1M |
|---|---|---|---|
| Candidates | 100,063 | 500,315 | 1,000,630 |
| Applications | 100,062 | 500,310 | 1,000,620 |
| Stage histories | 271,179 | 1,355,895 | 2,711,790 |
| Open requisitions | 520 | 2,600 | 5,200 |
| Communications | 50,161 | 250,805 | 501,610 |
| Audit rows (under-represented) | 17,831 | 89,175 | 178,350 |

In the tables below, cells are median times (3 runs; 1 run for analytics). "q" is the query count. **Bold** marks results that would be unacceptable in an interactive request under any reasonable target. No target is set here (D8.9-004).

### 2.1 Interactive paths

| Operation | 100k | 500k | 1M | Plan |
|---|---|---|---|---|
| Candidate list page + count, CHRO | 36 ms | 434 ms | 778 ms | full scan |
| Candidate list, manager (30 employees in scope) | 467 ms | **3.1 s** | **7.1 s** | full scan + dependent subquery |
| Candidate list, recruiter | 722 ms | **3.5 s** | **6.9 s** | same |
| Name search `LIKE`, CHRO | 149 ms | 1.16 s | **2.2 s** | full scan |
| Global search (4 columns), CHRO | 234 ms | 839 ms | 827 ms | full scan; stops early on hits |
| Name search, manager | 596 ms | **4.0 s** | **8.0 s** | full scan + dependent subquery |
| Duplicate detection (email + mobile) | 1.3 ms | 1.5 ms | 1.7 ms | index merge ✔ |
| Application list, CHRO | 49 ms | 176 ms | 422 ms | count is a full scan |
| Application list, manager | 14 ms | 36 ms | 444 ms | `recruiter_id` range |
| Application list, recruiter | 5.6 ms | 12 ms | 9.5 ms | index ✔ |
| Pipeline board, one requisition (555 apps, 4.4 MB) | 38 ms | 64 ms | 38 ms | index ✔ (page-level costs: §4) |
| Candidate timeline / audit history / communications | 1.6 / 0.7 / 0.6 ms | 1.6 / 0.6 / 0.6 ms | 2.2 / 0.6 / 0.7 ms | indexes ✔ |
| Audit list, newest | 1.0 ms | 0.8 ms | 1.0 ms | PK ✔ |
| Audit list, filter by action | 22 ms | 98 ms | 273 ms | scans in PK order (unindexed `action`) |
| Notification centre | 1.3 ms | 1.0 ms | 1.0 ms | morph index ✔ (history small in benchmark) |
| Action Center, recruiter (17 q) | 29 ms | 48 ms | 229 ms | |
| Action Center, manager (17 q) | 56 ms | **1.1 s** | **4.0 s** | stalled-candidates NOT EXISTS |
| Export scope count, CHRO / manager | 42 / 498 ms | 332 ms / **2.8 s** | 710 ms / **6.2 s** | candidate scope |
| Portal dashboard (one candidate) | 3.1 ms | 1.5 ms | 2.6 ms | indexes ✔ |
| Outcome due query (30-day) | 0.7 ms | 0.8 ms | 0.8 ms | index ✔ |
| Risk Radar scan, one requisition | 1.9 s / 123 q (first scan) | 123 ms / 48 q (repeat) | 153 ms / 48 q (repeat) | repeat scans find existing risks, so they are not comparable with the first |

### 2.2 Governed metrics (cold cache)

| Operation | 100k | 500k | 1M |
|---|---|---|---|
| All metrics, CHRO, this month | 3.4 s (95 q, 9 MB) | **26.0 s** (113 q, 21 MB) | **83.3 s** (136 q, 41 MB) |
| All metrics, CHRO, 365 days | **53.6 s** (288 q, 51 MB) | **FAILED** after > 23 min: MySQL 1390 (P89-PERF-027) | not run (would fail the same way) |
| All metrics, manager, this month | 0.46 s | **15.5 s** | **19.9 s** |
| All metrics, manager, 365 days | 0.59 s | **23.6 s** | **27.3 s** |
| `recruiter.outcomes`, one recruiter | 33 ms | 89 ms | 99 ms |
| `joining.offer_to_join`, 365 d, CHRO | ok (inside the set) | **FAILED** 6.5 s, MySQL 1390 | **FAILED** 21.3 s, MySQL 1390 |
| `pipeline.funnel`, 365 d | 2.1 s | **19.7 s** | **35.7 s** |
| `hiring.time_to_hire`, 365 d | 1.0 s / 39.6 MB | 6.8 s / **197 MB** | 13.1 s / **395 MB** (> 256 MB limit) |
| `sla.leg_compliance`, 365 d | 11.8 s / 37 MB / 160 q | **181 s** / 223 MB / 697 q | **880 s** / **446 MB** / 1,372 q |
| `pipeline.time_in_stage`, 365 d | **31.9 s** / 51 MB | not run (quadratic) | **stopped after 22.4 min** (offset 150,000; ≈ 12 s per 2,000-row chunk and rising) |
| `requisition.hiring_health` | 34 ms | 94 ms | 187 ms |

**Reading the tables:**
- Every indexed path is flat from 100k to 1M: duplicate detection, candidate 360, audit newest, notifications, portal, outcome due, recruiter lists. **The data size itself is not the problem.**
- The degrading paths are all full scans or whole-set algorithms. They grow at least linearly with *total* rows even when the viewer's own scope is constant. In the probe the manager's visible set stays at 30 employees while their candidate list goes from 0.47 s to 7.1 s.
- Three metric defects fail outright at scale instead of slowing down:
  - too many placeholders (P89-PERF-027);
  - memory above 256 MB (P89-PERF-028);
  - quadratic chunking (P89-PERF-029).

## 3. Database: query hotspots and index gaps

### 3.1 Confirmed full scans and their causes

| Path | Cause | Evidence |
|---|---|---|
| Hierarchy-scoped candidate list, count, export scope, pickers, rediscovery, AI search | `EXISTS(applications … recruiter_id IN …) OR created_by = ?`. The OR prevents a semi-join, so MySQL scans `candidates` (type ALL) with a dependent subquery per row. Filament re-runs the count on every render and keystroke. | `Candidate.php:102-117`; `CandidateResource.php:58-64`; EXPLAIN at 100k / 500k |
| Name and global search | `LIKE '%x%'` on code, name, mobile and email, plus `whereHas source`; no FULLTEXT; normalized columns unused | `CandidatesTable.php:35-57`; `HasGlobalSearch.php:193-245`; `CommandPalette.php:183-231` |
| Offer and interview lists (count) | correlated EXISTS / OR scopes | `OfferResource.php:46-60`; `InterviewResource.php:42-59` |
| Stage-history time ranges (StageActivity, TimeInStage, joining analytics) | `created_at` range without `new_stage`; `csh_stage_created_idx` leads with `new_stage` | `StageActivity.php:64-67`; `TimeInStage.php:64-65` |
| TimeToFill | aggregates **all** joined joinings, then filters by date | `TimeToFill.php:60-70` |
| Offers released | `offer_status_histories` has an index on `offer_id` only | `QueriesOffers.php:26-36` |
| Pipeline "interviews today", follow-up calendar, alerts, audit filter | `whereDate()` makes the predicate non-sargable (11 sites) | `Pipeline.php:192` and others |
| Audit list | default sort on unindexed `created_at`; unindexed `action` / `actor_kind` filters; `DISTINCT auditable_type` and the full user list on every render | `AuditLogsTable.php:24-118` |
| Action Center stalled candidates | `status` (low selectivity) + `created_at` + correlated NOT EXISTS | `RecruitmentActionCenterService.php:397-410` |
| Automation sweep | ORDER BY a correlated `max(id)` for every matching subject | `AutomationEngine.php:122-135` |
| Outcome evaluation (nightly) | due checks over **all** historical hires; `pendingOffers` scans all offers with 5 EXISTS | `OutcomeEvaluator.php:66-106,154-201` |
| Notifications | JSON-path filters on a **TEXT** `data` column, polled every 30 s per tab; JSON dedupe per alert | `NotificationCenter.php:93-98`; `NotificationDispatchService.php:106-114` |
| RAG | `VectorSearch` loads every published chunk and embedding per query | `VectorSearch.php:63-83` |

### 3.2 Composite-index gaps (31)

None of these was added; each is tied to a path above. ED-03 proposes adding them through reviewed migrations once approved.

| Table | Proposed index | Serves |
|---|---|---|
| candidate_applications | (status, current_stage, last_activity_at) | pipeline columns, candidate ageing, `application.stuck` sweep |
| candidate_applications | (recruiter_id, status, current_stage) | scoped pipeline and lists |
| candidate_applications | (requisition_id, status) | requisition pipeline, talent signals |
| candidate_applications | (created_at) | pickers, distribution analytics |
| candidate_applications | (next_followup_at) | list sort |
| candidate_applications | (recruiter_id, candidate_id) | rewritten candidate scope (ED-02) |
| candidate_stage_histories | (created_at) | StageActivity, TimeInStage, joining analytics |
| candidate_stage_histories | (candidate_application_id, new_stage, created_at) | SLA latest-entry subqueries |
| offer_status_histories | (offer_id, to_status, created_at) / (to_status, created_at) | offers released |
| interviews | (status, scheduled_at) | Action Center, reminders |
| offers | (status, offer_expiry) | expiring offers |
| candidate_joinings | (status, expected_doj) / (status, updated_at) | joining risk, joining outcomes |
| candidate_communications | (created_at) | communication analytics |
| automation_executions | (automation_rule_id, started_at) / (created_at) | daily-limit count, analytics |
| recruiter_actions | (created_at) | analytics |
| hiring_outcomes | (hiring_outcome_snapshot_id, outcome_type, is_current) / (offer_id, outcome_type, is_current) | nightly due checks |
| hiring_outcomes | (observed_at) | P83-BACKLOG-004 |
| hiring_risks | (status, last_seen_at) | auto-resolve |
| hiring_memory_records | (captured_at) / (memory_type, designation_id, is_current, captured_at) | list sort, Role DNA history |
| candidates | (created_by, created_at) / (updated_at) | recruiter activity, rediscovery |
| recruitment_daily_activities | (recruiter_id, activity_type, activity_datetime) | daily metrics |
| recruitment_followups | (recruiter_id, status, followup_date) | Action Center |
| audit_logs | (created_at) / (action, created_at) / (auditable_type, auditable_id, created_at) | audit list and history |
| notifications | (notifiable_type, notifiable_id, read_at, created_at) | bell and centre |
| jobs | (queue, reserved_at, available_at) | worker pop with many delayed rows |

**Redundant:** `intelligence_evidence` owner morph index, which is a prefix of `intel_evidence_owner_subject`.

### 3.3 Locks and deadlock risk

- 24 `lockForUpdate` sites; no `sharedLock`. All run under REPEATABLE READ.
- **`code_sequences`** serialises every candidate / application / referral / employee code. The lock is held until the *outer* commit, including resume file I/O in career applications. `firstOrCreate` under FOR UPDATE takes a gap lock on the first call of a new year (P89-PERF-020).
- **Lock-order inversion** (P89-PERF-021):
  - offer / interview transitions lock offer (or interview) → application;
  - the closure cascade locks application → interviews → offers.
  
  A concurrent reject and accept-offer on the same application can deadlock. The outcome is an error, not corruption.
- **I/O inside transactions:** an offer-letter PDF is rendered and stored while offer and application rows are locked.
- **Long transactions:**
  - `OutcomeEvaluator` runs one transaction per 200-record chunk, with gap-locking inserts;
  - `CleanupAutomation` runs one unbounded DELETE.
- The data-integrity races (missing locks) are catalogued as P89-DQ in the discovery document.

## 4. Application

| Item | Finding | Evidence |
|---|---|---|
| Hierarchy | `visibleEmployeeIdsFor` is not memoized, with 87 call sites. Metrics call it 3× per metric (`applications`, `basis`, `fingerprint`). Per-row policy checks repeat it. | `HierarchyService.php:26-37`; `MetricScope.php:30-33` |
| Pipeline page | `recruiterOptions()` loads **every** scoped application plus its recruiter on each Livewire round-trip; the "sourced" count runs once per column (~11); there is no composite index for column cards | `Pipeline.php:272-325` |
| Dashboard | 16+ widgets, mostly `$isLazy = false`; `positionHealth` 3–5× per load; analytics are uncached outside the governed metrics | `Dashboard.php:115-151` |
| N+1 | interviews table (2 / row), joinings, offers, requisitions (`designation` hidden column), incentive `effectiveAmount()` SUM per row (8.5 PF-10) | schema review §3 |
| Whole-set hydration | SLA breach sweep; 8 alert checks; Risk Radar interviews; TimeToHire; rediscovery (2,000 candidates with nested relations) | `RecruitmentSlaService.php:135,160-166`; `DispatchRecruitmentAlerts.php` |
| Request-path heavy work | LibreOffice Word→PDF (≤ 120 s) in the release request; offer PDF inside the transaction; interviewer import synchronous (PF-88-04); portal-upload listener synchronous (PF-88-09); talent-pool bulk add uncapped (PF-88-05) | — |
| Guard rails | no `preventLazyLoading`; memory limit 256M on every process | `docker/php/local.ini:2` |

## 5. Queue

### 5.1 Topology (unchanged in discovery)

| Worker | Queues (strict order) | Processes | Timeout | Memory |
|---|---|---|---|---|
| `queue` | communications → notifications → default | 1 | 120 s | 128 MB default |
| `queue-automation` | automation → default | 1 | 120 s | 128 MB |
| `queue-background` | intelligence → integrations → default | 1 | 300 s | 128 MB |

Shared settings:
- `--tries=3`;
- `--max-time=3600`;
- `retry_after` 330;
- database driver, `jobs` indexed on `queue` only.

### 5.2 Burst models (PROJECTED)

| Burst | Producer | Consumer | Side effects |
|---|---|---|---|
| **100k candidate messages** | ≈ 17 min, 1.1M queries (11 queries / 10 ms each, measured in 8.7); ≈ 5 inserts per message | one worker: **≈ 6.5 h** at 0.2 s provider latency; **≈ 29 h** at 1 s; ≈ 240 / h at the 15 s timeout | `notifications` and `default` get **no throughput** for the whole drain (OTP 10 min and reset 60 min expire; the backlog alert itself is queued behind it). Reliability sweep ≈ 200–300k queries per 5-min run. Duplicate jobs once `uniqueFor` 3600 expires. 200–300k webhook callbacks (600 / min per IP). More workers without a provider throttle produce 429s, then the circuit opens and work churns. |
| **100k in-app notifications** | per alert: reroute check, **unindexed JSON dedupe scan** of the recipient's history, `Cache::add` row, job | each job is 1 INSERT; fast once it runs | cannot start while `communications` is busy. Producer cost grows with each recipient's notification history (P87-009). The first `dispatch-alerts` after midnight emits the day's set as a burst. |
| **10k stage moves × 10 org-scope rules** (automation storm) | synchronous in the requests: ≈ 35 queries per event per rule, so 100k executions and jobs created | ≈ 1.4–4.2 h on one automation worker; only 5,000 act (500 / rule / day cap) | ≈ 95k Skipped rows **never pruned**; +20k intelligence jobs (2 listeners per stage change); daily-limit count slows as the rule's history grows |
| **Large Filament export** | ≈ 102 jobs per 10k rows | on `default`, runs only when every higher queue is idle | unencrypted metadata payload (P89-SEC-012) |

### 5.3 Measuring queue throughput

A live worker throughput run was **not** performed in discovery. It would require running workers against fake providers, which is a configuration exercise left for the approved benchmark plan (`phase-8-9-capacity-model.md` §4). The figures above combine the 8.7 measurements (send path 10.1 ms; sweep 560 ms at 50k / 250 stuck) with provider latency assumptions, and are labelled PROJECTED.

## 6. Scheduler, intelligence and outcomes

| Task | Cadence | Scale behaviour | Finding |
|---|---|---|---|
| `notifications:dispatch-alerts` | hourly, foreground | unbounded `->get()` calls; SLA sweep **585 MB at 100k** (8.5) vs 256M, so a PHP fatal skips the remaining checks; JSON dedupe per alert | P89-PERF-004 |
| `intelligence:refresh` | hourly, background | 414 s cold / 19.5 s warm at 520 open requisitions; ≈ 0.8 s per requisition cold; Risk Radar scan of one requisition **1.9 s / 123 queries** (BENCHMARK, 100k); exceeds the hour at ≈ 4,500 open requisitions | P89-PERF-005 |
| Hiring Health time series | inside the refresh | new snapshot every 6 h per open requisition, ≈ 3.6 KB JSON + ≈ 21 evidence rows, never pruned; ≈ 66 KB per open-requisition-day | P89-PERF-026 |
| Talent signals | inside the refresh | ≈ 6 queries per application even when fresh; only the first 200 active applications per requisition | P89-PERF-023, P89-DQ-015 |
| `recruitment:automation:dispatch` | 15 min | correlated `max(id)` ORDER BY over every matching subject per rule | P89-PERF-009 |
| `recruitment:automation:process` | 5 min | 200 due runs + 200 escalations inline (ceiling 2,400 / h each, accepted in 8.7) | — (PF-87-10 accepted) |
| `reliability:sweep` | 5 min, foreground | O(backlog) | P89-PERF-007 |
| `outcomes:evaluate` | daily | rescans all hires and offers; learning refresh loads full history into memory | P89-PERF-014 |
| `communications:send-reminders` | hourly | each interview in the next 24 h re-checked ≈ 24× a day | (S, cost) |
| `recruitment:automation:cleanup` | daily | one unbounded DELETE | P89-PERF-021 |
| Same-minute sequencing | — | a slow `dispatch-alerts` delays `reliability:sweep` and `queue:health-check` in the same minute | P89-PERF-004 |

## 7. Search and analytics

**Search thresholds (input to D8.9-015; no engine introduced):**

| Lookup | Today | Scales? |
|---|---|---|
| Email / mobile / code exact | the duplicate detector uses `*_normalized` indexes: 1.3–1.5 ms at 100k–500k (BENCHMARK) | yes, at any tier, *if* search routes exact and prefix input to these indexes |
| Name substring | `LIKE '%x%'` full scan: 149 ms (100k) → 1.16 s (500k) → 2.2 s (1M), CHRO | no; ≈ linear in candidates |
| Name substring, hierarchy-scoped | full scan + dependent subquery: 0.6 s → 4.0 s → 8.0 s | no; the scope rewrite (ED-02) removes most of it |
| Skills / location | JSON / free text, AI tool only | not indexed |

**Analytics (performance vs semantics).**
- No governed metric definition is wrong or changed. The cost comes from evaluating them live over full fact tables. The worst are:
  - `pipeline.time_in_stage` (full scan of stage histories);
  - `sla.leg_compliance` (160 queries);
  - `pipeline.funnel`;
  - `hiring.time_to_hire` (hydrates every hire: 40 MB at 100k, 395 MB at 1M).
- Manager-scoped metrics are cheap at 100k (≈ 0.5 s for all of them) but reach 15–27 s at 500k–1M. Their scope narrows the *result*, not the *scan*, because the stage-history `created_at` ranges cannot use an index.
- The 600 s cache is keyed per scope fingerprint. Every CHRO-scope viewer shares one entry; there is no warm-up and no stampede lock.
- **Options for D8.9-016 (none chosen):**
  - the stage-entry fact table (P85-BACKLOG-007);
  - materialized daily aggregates;
  - a scheduled cache warm-up for organisation-wide scope;
  - the indexes in §3.2.

  All of them must reproduce today's numbers exactly. A parity test against the live definitions is a precondition.

## 8. Findings (P89-PERF)

Severity reflects impact at the tier where it appears.

| ID | Sev | Component | Evidence | Impact | Current control | Direction | Impl. dep. | Decision dep. |
|---|---|---|---|---|---|---|---|---|
| **P89-PERF-001** | **High** | Governed metrics, organisation-wide | §2: all metrics 365 days cold **53.6 s at 100k**, **failed at 500k**; this month **26 s at 500k / 83 s at 1M**; manager scope 0.5 s → 20–27 s (1M); `sla.leg_compliance` 11.8 s → 880 s | CHRO dashboards and reports unusable cold above ≈ 100k; manager dashboards degrade too, because the scans cover all rows | 600 s cache per scope | indexes (§3.2) + materialization; cache key includes settings (P86-010); smarter invalidation (P85-003) | medium–large | D8.9-004, 016 |
| **P89-PERF-002** | **High** | Candidate hierarchy scope | EXPLAIN type ALL + dependent subquery; manager list 0.47 s (100k) → **3.1 s** (500k) → **7.1 s** (1M); recruiter 0.72 → 3.5 → **6.9 s**; manager export count → 6.2 s | the main page for most users slows linearly with total candidates, on every keystroke | none | rewrite as `IN (… UNION …)` with (recruiter_id, candidate_id) (ED-02) | small–medium | D8.9-013 |
| **P89-PERF-003** | **High** | Search | `LIKE '%x%'` full scans; name search 0.15 → 1.16 → **2.2 s** (CHRO), 0.6 → 4.0 → **8.0 s** (manager), at 100k / 500k / 1M | search unusable at 500k+ | none | route exact / prefix to indexed columns; FULLTEXT or an engine only by decision | small → large | D8.9-015 |
| **P89-PERF-004** | **High** | `dispatch-alerts` | 585 MB at 100k vs 256M (8.5 measurement); 14 unbounded checks; fatal aborts the rest | alerts silently stop at ≈ 100k | `withoutOverlapping` | chunk / count, no hydration (ED-04) | small–medium | — |
| **P89-PERF-005** | **High** | Intelligence refresh / Risk Radar | 414 s cold at 520 (8.7); scan 1.9 s / requisition (BENCHMARK) | exceeds its hourly cadence at ≈ 4,500 open requisitions; stale risks | `withoutOverlapping` 120 min | incremental refresh; per-risk batched writes (P87-004) | medium | D8.9-006 |
| **P89-PERF-006** | **High** | Communications throughput and notification starvation | §5.2 | 100k burst 6.5–29 h; OTP / reset / alerts blocked | 3 worker groups | split `notifications` (ED-05); worker scaling plus provider throttle | small + infra | D8.9-005, 018 |
| P89-PERF-007 | Medium | Reliability sweep | O(backlog) every 5 min, foreground | ≈ 200–300k queries per run in a 100k backlog; delays other tasks | chunking | batch re-dispatch; cap per run (ED-04) | small | — |
| P89-PERF-008 | Medium | Automation storm | executions created synchronously per rule; caps at run time | 100k jobs for 5k useful runs; request latency during bulk events | per-rule daily cap | evaluate cheap conditions and caps before creating executions | medium | Product (automation behaviour change) |
| P89-PERF-009 | Medium | Automation sweep | correlated `max(id)` ORDER BY | sorts 10⁵⁺ rows with a subquery each, every 15 min per rule | 500-row limit | join on a precomputed latest execution, or a covering index | small | — |
| P89-PERF-010 | Medium | Pipeline page | §4 | memory and time grow with all scoped applications | none | load recruiter options separately; one grouped count; indexes | small | — |
| P89-PERF-011 | Medium | Index coverage | §3.2 (31 gaps; `jobs` pop index) | full scans and filesorts across lists, metrics and jobs | single-column indexes | reviewed migrations (ED-03) | small each | D8.9-013 |
| P89-PERF-012 | Medium | HierarchyService | not memoized; 87 call sites | dozens of closure-table queries per request and job | none | per-request / per-job memo (ED-01) | small | — |
| P89-PERF-013 | Medium | Audit and notification growth | unindexed sort and filters; JSON dedupe on TEXT; nothing pruned | audit UI and alert dispatch slow with history | none | indexes; dedupe-key column; partitioning (D8.9-024). **Retention stays deferred.** | small–medium | D8.9-024 |
| P89-PERF-014 | Medium | Outcomes nightly | rescans all history; learning refresh loads everything | run time and memory grow with every hire | 180-min overlap guard | due-date indexes; incremental checkpoints; streaming aggregation | medium | — |
| P89-PERF-015 | Medium | Dashboard fan-out | 16+ eager widgets; `positionHealth` 3–5× | slow first paint; load multiplied per viewer | metric cache | lazy widgets; per-request memo (ED-09) | small | — |
| P89-PERF-016 | Medium | MySQL as cache, session and queue store | every lock, limiter, dedupe key, heartbeat and session write hits the primary; expired cache rows never pruned | write amplification; buffer-pool pressure; a DB outage takes everything | none | housekeeping (ED-08); Redis only by decision (P87-005) | small / infra | D8.9-014 |
| P89-PERF-017 | Low | Exports on `default` | `Exporter::getJobQueue()` null; ≈ 102 jobs per 10k-row export | exports wait behind everything | 10k-row cap | named export queue (ED-06) | small | — |
| P89-PERF-018 | Low | RAG `VectorSearch` | loads every chunk and embedding per query | memory and time grow with the knowledge base | small corpus today | bounded candidate set or vector index | medium | D8.9-016-like (AI) |
| P89-PERF-019 | Low | Talent rediscovery | candidate OR-scope + 3 NOT EXISTS + ORDER BY `updated_at` (no index), 2,000 rows with nested relations | slow on demand at scale | 2,000 cap | index + scope rewrite | small | — |
| P89-PERF-020 | Low | `code_sequences` | single row per prefix and year held to the outer commit; gap lock on first use each year | career-site bursts serialise; year-start deadlocks | row lock | seed next year's rows; shorten lock scope | small | — |
| P89-PERF-021 | Low | Deadlocks / long transactions | lock-order inversion; PDF in transaction; chunk transactions; unbounded DELETE | sporadic errors under concurrency | retries in some listeners | consistent lock order; I/O outside transactions; batched deletes | medium | D8.9-029 |
| P89-PERF-022 | Low | Incentive `effectiveAmount()` N+1 (8.5 PF-10) | `RecruiterIncentiveCalculation.php:80` | per-row SUM in dashboard and exporter | none | `withSum` | trivial | — |
| P89-PERF-023 | Low | Talent signals | ≈ 6 queries per application even when fresh; 200-per-requisition cap | refresh cost; coverage gap (P89-DQ-015) | cap | skip fresh; page through | small | — |
| P89-PERF-024 | Medium | Synchronous heavy work in requests | LibreOffice ≤ 120 s; offer PDF in transaction; interviewer import (PF-88-04); portal-upload listener (PF-88-09); talent-pool bulk add uncapped (PF-88-05) | Apache workers held; timeouts; long locks | upload size limits | queue them; cap bulk add | small–medium | — (security twins SEC-88-25 / SEC-88-21 stay deferred C) |
| P89-PERF-025 | Medium | `queue-background` single process | embeddings near 300 s (P87-011); LLM calls 60 s | `integrations` (calendar, job boards) starve | ordering | separate process for integrations, or split long AI jobs | small + infra | D8.9-018 |
| **P89-PERF-027** | **High** | Unbounded id lists sent as bound parameters | **BENCHMARK, 500k:** the 365-day organisation-wide metric run **failed** with MySQL error 1390 "Prepared statement contains too many placeholders". `OfferToJoin.php:64-68` plucks every accepted application in the period and passes the ids to one `whereIn`. MySQL allows at most 65,535 placeholders. Same pattern, from code (PROJECTED): `distributionAnalytics` (`RecruitmentAnalyticsService.php:842-850`, every channel-attributed application in the last 90 days). | `joining.offer_to_join` throws, so the dashboard or report that requests it errors, once a period holds > 65,535 accepted offers; longer periods hit the limit sooner. The job-posting distribution widget fails above 65,535 channel applications in 90 days. A hard failure, not a slowdown. | none. Other metrics chunk (`SlaLegCompliance` by 2,000) or use subqueries (`TimeInStage`). | use a subquery or join instead of a materialized id list, or chunk. Same result, so **no metric-definition change**. | small | — (ED-13) |
| **P89-PERF-028** | **High** (≥ ≈ 600k) | Metric memory: `hiring.time_to_hire`, `sla.leg_compliance` | **BENCHMARK, 365-day organisation-wide peak PHP memory:** `time_to_hire` **39.6 MB at 100k → 394.8 MB at 1M**; `sla.leg_compliance` **37.4 MB → 445.9 MB** (and 11.8 s → 880 s, 1,372 queries). `TimeToHire.php:66-68` hydrates every hire with three eager-loaded relations. `SlaLegCompliance` collects every leg end and start into PHP arrays. | above the 256 MB `memory_limit` the web request dies with a fatal error, so the CHRO dashboard tile or report fails. Linear projection crosses 256 MB at about 600–650k candidates. | none | stream with `lazyById` / plain rows and aggregate incrementally (same arithmetic, same result) | small–medium | — (ED-13) |
| **P89-PERF-029** | **High** (≥ ≈ 250k) | Metric algorithm: `pipeline.time_in_stage` | `TimeInStage.php:71-75` calls `->chunk(2000)` on `whereIn('id', <stage-history subquery>)`. `chunk` paginates with **OFFSET**, so every chunk re-runs the subquery and skips past all earlier rows. The cost is quadratic in the number of applications that moved in the period. **BENCHMARK:** 31.9 s at 100k; at 1M **stopped after 22.4 min** at offset 150,000, with each 2,000-row chunk taking ≈ 12 s and growing. | never completes at 1M organisation-wide; exceeds the 120 s `max_execution_time` long before that (PROJECTED ≈ 250k) | 600 s cache only after a success | `chunkById` / `lazyById` on a materialized or joined set (same rows, same result) | small | — (ED-13) |
| P89-PERF-026 | Medium | Intelligence time series | new Hiring Health snapshot (≈ 3.6 KB) + ≈ 21 evidence rows per open requisition every 6 h; nothing pruned | ≈ 24 GB / year per 1,000 open requisitions (PROJECTED), more than all core data at 1M candidates; larger backups | none | write only on change, or compact superseded snapshots (behaviour / retention decision) | small | D8.9-024 |
