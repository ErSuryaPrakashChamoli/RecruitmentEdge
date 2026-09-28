# Phase 8.8 Performance: Candidate-Facing, Import/Export and Retention (Discovery)

**Baseline:** `a98b0c2`. **Method:** code-path analysis plus read-only measurements on the throwaway `hrms_p87_perf` database (MySQL 8.4; 100,002 candidates, 100,001 applications, 50,100 messages, 17,476 audit rows; array cache; single process). Nothing was optimised and no index was added. Figures at 500k–5M are **extrapolations**, marked as such.

## 1. Measurements (100k)

| Operation | Time | Queries | Notes |
|---|---|---|---|
| Candidate duplicate detection (`detect`, name + email + mobile) | 1.8 ms | 1 | `index_merge` over the four normalized-identity indexes |
| Candidate duplicate detection (email + mobile) | 1.7 ms | 1 | |
| Candidate export scope count, CHRO (all 100,002) | 103 ms | 8 | `Candidate::visibleTo` |
| Candidate export scope count, manager (964 visible) | **639 ms** | 5 | hierarchy scope evaluated through applications; the cost grows with total candidates, not with the manager's share |
| Streaming 100k candidate rows (id, name, mobile, email), `lazyById(1000)` | 174 ms | 101 | database side of an export is cheap; the export job's PHP/CSV cost is not measured |
| Audit rows for one candidate (`auditable_type` + `auditable_id`) | 9.8 ms | 1 | `audit_logs_auditable_idx` |
| Audit rows for one portal account (actor) | — | — | `audit_logs_actor_type_actor_id_index` used (EXPLAIN) |
| Portal timeline `forPortal` for one candidate | 6.8 ms | 2 | includes the 8.7 sent-message filter |
| Candidate name search `LIKE '%x%'` | 38 ms | 1 | **full scan** (97,916 rows examined) |
| Portal reads per candidate (applications, invitations, bookings, messages) | — | — | every query filtered by `candidate_id` (indexed FKs); messages limited to 10 |

## 2. Findings

| ID | Operation | Current complexity | Observed | Expected scale | Risk | Recommended direction | Schema/index change? |
|---|---|---|---|---|---|---|---|
| PF-88-01 | Hierarchy-scoped candidate list and export (`Candidate::visibleTo` for non-org roles) | O(total candidates) per query, via `whereHas` on applications | 639 ms at 100k for a 964-row manager scope | ~3 s at 500k, ~6 s at 1M (extrapolated, linear) | slow candidate list, count and export start for managers and recruiters | rewrite the scope as a join or `whereIn` on the application recruiter index; measure before changing | likely no (maybe a composite index on `candidate_applications(recruiter_id, candidate_id)`) |
| PF-88-02 | Candidate name / code search (`LIKE '%x%'`) in tables, global search and portal-less staff screens | full table scan | 38 ms at 100k | ~0.4 s at 1M, per keystroke (extrapolated) | search latency; database load under many concurrent users | prefix search on normalized columns, or a full-text index | yes (full-text index) or no (prefix only) |
| PF-88-03 | Exports (7 Filament exporters) | queued, chunked by Filament; **no row cap** | DB streaming 100k rows: 174 ms | 1M-row exports produce very large files on the private disk | disk growth (files never deleted); worker time on the `default` queue path; large downloads | row caps, retention, run on a named queue (`default` should stay empty since 8.7) | no |
| PF-88-04 | Interviewer import | synchronous inside the Livewire request; one transaction; formulas evaluated; no row cap (5 MB file) | not measured | a formula-heavy or very large sheet exceeds the request timeout | request timeout; long transaction lock | queue it, cap rows, disable formula calculation | no |
| PF-88-05 | Talent pool bulk add ("select all") | one synchronous transaction with a row lock, a timeline write, an audit row and an event per candidate; no cap | not measured | thousands of rows in one request | request timeout; long locks | cap or queue | no |
| PF-88-06 | Audit log growth | unbounded; ~1 row per write, candidate rows carry full PII diffs | 17k rows in the benchmark set | 10M+ rows at 1M candidates (estimate) | table size, backup size, slower audit filters (date filters not measured) | retention (D8.8-031); partitioning is out of scope | possibly (retention only) |
| PF-88-07 | Document storage | local private disk, no quota, no deduplication; 5 MB (portal/career), 12 MB (staff default) | not measured | at 1M candidates × ~2 files × ~500 KB ≈ 1 TB (estimate) | disk capacity; backups; files orphaned on delete | object storage decision, retention, orphan cleanup | no |
| PF-88-08 | Sessions (`database` driver) | lottery GC; candidate and staff share the table | not measured | high portal traffic grows `sessions` quickly | table bloat; lookup is by primary key, so latency stays low | scheduled cleanup; consider cache/redis sessions later (infrastructure non-goal) | no |
| PF-88-09 | Portal document upload recruiter alert | `NotifyRecruitersOfPortalDocument` runs synchronously in the upload request | not measured | fine at low volume | slower uploads when many recruiters are alerted | make it queued (the notification itself is queued since 8.7) | no |
| PF-88-10 | Career site index and XML feed | unthrottled; `LIKE` search; feed up to 500 rows | not measured | scraping or bursts at high traffic | database load from anonymous traffic | cache the feed; throttle; see SEC-88-21 | no |
| PF-88-11 | Export query scope fixed at dispatch | literal id lists serialized into the job for scoped users | not measured | very large hierarchies produce very large job payloads | queue payload size (8.7 payload rules apply: ids only, which holds) | keep; measure payload size for the largest hierarchy | no |
| PF-88-12 | Future retention / anonymization runs | none exist yet | — | 1M candidates × many child tables | a naive per-candidate erasure loop would be long and lock-heavy | design as chunked, queued, resumable jobs with per-item isolation (8.7 patterns) | depends on design |

## 3. Scale view

| Scale | Assessment |
|---|---|
| 100k candidates (measured) | acceptable, except PF-88-01 for scoped roles (0.6 s) |
| 500k candidates | PF-88-01 and PF-88-02 become noticeable (seconds); exports and documents need retention |
| 1M candidates / 5M applications | PF-88-01/02 need a redesign; audit and document volumes need retention and possibly object storage; the database queue limit from 8.7 (~100k applications / 500 open requisitions) is reached first — Redis remains a separate decision (P87-BACKLOG-005, non-goal here) |
| High-volume portal traffic | portal queries are indexed and small; sessions table and IP-only limits (SEC-88-10) are the constraints |
| High-volume scheduling | slot booking uses a row lock per slot (correct); the concurrency gap is correctness (SEC-88-20), not throughput |

No N+1 was found on the portal pages reviewed; per-page query counts were not instrumented and should be measured in 8.8G.
