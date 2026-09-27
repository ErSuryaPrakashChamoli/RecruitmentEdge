# Phase 8.5: Performance

**Method:**
- **Same data on both sides:** one throwaway MySQL 8.4 database (`hrms_p85_perf`, since dropped), measured with the **Phase 8.4 code** (a `git archive a38a8d9` export with its own autoloader) and the **Phase 8.5 code**. Only the code differs between the two columns.
- **Organisation:** 3,011 employees (CHRO → 10 VP → 100 managers → 2,900 recruiters) and 200 requisitions.
- **Applications:** grown to 10k, then 100k, with proportional stage history, interviews, offers (with release history) and joinings. At 100k there are 270k stage-history rows, 42k interviews, 21k offers and 9.8k joinings.
- **Viewers:** each cell is time (ms) / queries / peak MB for CHRO (sees everything), a VP (~300 people) and a manager (~30).
- **Runs:** one timed run per cell, caching off (`METRICS_CACHE_TTL=0`), array cache store.
- **Harness:** `p85_perf_seed2.php`, `p85_perf_run.php` (8.4), `p85_perf_after.php`, `p85_perf_metrics.php`, kept in the session scratchpad, outside the repository.

## 100k applications — analytics methods, 90-day period (CHRO / VP / manager)

| Method | Phase 8.4 | Phase 8.5 |
|---|---|---|
| SLA open breaches (hourly alert sweep) | **96,480 / 78,100 q / 858 MB** · 9,229 · 907 | **10,256 / 24 q / 585 MB** · 1,201 · 138 |
| SLA stage turnaround | 15,283 / 21 · 4,183 · 522 | 2,383 / 46 · 822 · 139 |
| Conversion breakdown (by recruiter) | 7,917 / 8,705 q · 847 · 88 | 886 / 5 q · 209 · 25 |
| Candidate aging | 4,753 · 521 · 66 | 355 · 185 · 10 |
| Source analytics | 2,745 / 163 q · 912 · 659 | 1,142 / 9 q · 365 · 59 |
| Position health | 1,654 / 603 q · 117 · 11 | 690 / 3 q · 67 · 9 |
| Turn-up analysis | 544 · 107 · 20 | 310 · 70 · 20 |
| Funnel | 511 · 942 · 136 | 619 · 88 · 20 (now a cohort funnel) |
| Joining analytics | 239 · 225 · 47 | 270 · 180 · 57 |
| Time to hire (365 days) | 830 (mean) | 1,175 (median, frozen start) · 138 · 27 |
| Interview analytics | 1,919 · 406 · 45 | 2,918 · 367 · 60 |
| Offer analytics | 1,291 · 195 · 26 | 1,921 · 396 · 180 |
| Cost per hire (365 days) | 13 · 57 · 14 | 32 · 68 · 43 |
| Outcome report | 53 · 268 · 125 | 35 · 227 · 93 |

**Faster:** the sweeps and breakdowns that were one query per application, per requisition or per group are now grouped queries (PF-1 to PF-6):
- the alert sweep is **9× faster with 3,250× fewer queries**;
- conversion breakdown is **9× faster**;
- candidate aging is **13× faster**;
- the SLA turnaround is **6× faster**.

**Slower, explained:**
- **Interview and offer analytics** now also compute the governed turn-up, no-show and acceptance metrics: two extra grouped queries each, +0.6 to 1.0 s for view-all at 100k.
- **Time to hire** reads each hire's frozen start from its snapshot: +0.35 s for view-all over 365 days.
- **The funnel** is now a cohort funnel: one grouped query, comparable with 8.4.

All regressions are view-all only and sub-second to about 1 s; VP and manager views are faster or equal.

## 100k applications — each registered metric, 90-day period (CHRO / VP / manager)

| Metric | ms (queries) |
|---|---|
| `hiring.time_to_hire` | 383 (11) · 44 · 22 |
| `hiring.time_to_fill` | 25 · 35 · 28 |
| `hiring.hires` | 9 · 14 · 8 |
| `pipeline.time_in_stage` | 5,582 (18) · 254 · 34 |
| `pipeline.funnel` | 662 (1) · 91 · 14 |
| `pipeline.stage_activity` | 1,793 (4) · 373 · 52 |
| `offer.acceptance_rate` | 298 (1) · 125 · 71 |
| `joining.join_rate` / `no_show_rate` / `dropout_rate` | 25–27 · 33–45 · 17–24 |
| `joining.offer_to_join` | 146 · 63 · 10 |
| `interview.turn_up_rate` / `no_show_rate` | 314–341 · 62–76 · 11–13 |
| `source.source_to_join` | 180 · 42 · 16 |
| `cost.cost_per_hire` | 13 · 35 · 30 |
| `sla.leg_compliance` | ≈ 2,380 (same computation as stage turnaround above) |
| `requisition.ageing` | 48 · 15 · 5 |
| `recruiter.activity` | 696 · 495 · 32 |
| `team.outcomes` | 432 · 219 · 128 |
| `outcome.*` | 2–63 |

**Optimisations made after the first measurement:**

| Metric | First measurement | Final |
|---|---|---|
| Funnel | 3,383 ms / 75 queries (hydrated cohort) | 662 ms / 1 query (one grouped SQL aggregate) |
| Time in stage | 15.7 s | 5.6 s (plain rows and integer timestamps) |
| SLA compliance | 9.1 s | 2.4 s |
| Joining analytics | 2.1 s | 0.27 s (direct Selected count) |

## 10k applications (CHRO): selected

| Method | Phase 8.4 | Phase 8.5 |
|---|---|---|
| SLA open breaches | 10,872 / 7,854 q | 1,153 / 24 q |
| Conversion breakdown | 2,804 / 4,931 q | 115 / 5 q |
| Source analytics (VP) | 8,010 | 58 |
| Position health | 631 / 603 q | 143 / 3 q |
| Candidate aging | 415 | 37 |

## Indexes (D45)

| Index | Serves | Write overhead | Migration risk | Rollback |
|---|---|---|---|---|
| `csh_stage_created_idx` on `candidate_stage_histories (new_stage, created_at)` | Stage entries in a period (funnel, stage activity, SLA legs, recruiter stage actuals) | One entry per history row (append-only table) | The longest build (≈ 270k rows at 100k applications); online on InnoDB | Drop the index |
| `ca_application_date_idx` | The application cohort | Insert only | Low | Drop |
| `offers_offer_date_idx` | Offer volume, recruiter offer actuals | Insert only | Low | Drop |
| `cj_actual_doj_idx` | Hires, time to hire, cost per hire, join rate | `actual_doj` is set once | Low | Drop |

## Caching (D14)

- Period metrics are cacheable for 10 minutes. The key covers metric, version, the viewer's visible-team fingerprint, period and filters. Results are stored as plain arrays.
- Real-time metrics (ageing, Hiring Health status) are never cached.
- All figures above are uncached, so they show the worst case.

## Still to do (8.9)

| Backlog | Item |
|---|---|
| P85-BACKLOG-006 | Stream the breach sweep instead of returning every breaching application |
| P85-BACKLOG-007 | Stage-entry fact table for time in stage |

A view-all dashboard at 100k is now dominated by `stage_activity` (≈ 1.8 s, counted three times for the trend cards) and interview analytics (≈ 2.9 s).
