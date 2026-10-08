# Phase 8.2: Commit Plan

Phase 8.2 was committed incrementally on `feature/sep_25_hrm`, on top of the Phase 8.1 release (`986e7b836d478a2e9b07835bdbe57d7854f830cb`). **Nothing has been pushed.**

**Grouping:** the commits follow the approved ten-commit structure, with two disclosed deviations:
- **An extra fix commit (`b0ae95d`).** Commit 4 (`cc2ab92`) was committed with one failing convention test: `TableRowInteractionsTest` requires that every resource with an edit page also has a view page. History was not rewritten. The view page was added in `b0ae95d`, so `cc2ab92` alone has one red test and every commit from `b0ae95d` onwards is green.
- **Where backfill lives.** `outcomes:backfill` sits in the permissions / hierarchy / security hardening commit (8), because it depends on the evaluator's catch-up window introduced there.

**State of each commit:** Pint-clean and scanned for secrets. Migrations are additive.

## Commits

| # | Commit | Contents | Suite at commit |
|---|---|---|---|
| 1 | `258e2e2` Phase 8.2 — outcome taxonomy and data model | Outcome enums (type, category, state, result, confidence, capture mode, separation reason, insight kind and status), four additive tables, immutable models, factories, `config/outcomes.php` | 1,320 passed |
| 2 | `65d4969` Phase 8.2 — outcome observation and calculation engine | `OutcomeService` (idempotent record, supersede, correct / void / confirm, audited), `OutcomeCalculator` (joining, offer, process), `OutcomeEvaluator`, `outcomes:evaluate` scheduled daily | 1,329 passed |
| 3 | `819112a` Phase 8.2 — joining events and hiring snapshot | `CandidateJoined` and `EmployeeConvertedFromCandidate` (ids only), `HiringSnapshotService`, `RecordHiringOutcomes` listener, hire memory moved from the pipeline stage to the joining record | 1,338 passed |
| 4 | `cc2ab92` Phase 8.2 — separation and status observations | Status observations at 30 / 90 / 180 days, separation record (resource, policy, audit), permissions and additive grant migration | 1,348 run, **1 failed** (the convention test above) |
| 4a | `b0ae95d` Phase 8.2 — separation view page | Read-only separation view page; fixes the convention test | 1,349 passed |
| 5 | `e8ca784` Phase 8.2 — outcome analytics and dashboard | `OutcomeAnalyticsService`, Hiring Outcomes dashboard, Outcome Records resource with audited confirm / correct / void; Unknown → Confirmed by correction only | 1,361 passed |
| 6 | `ccdf13f` Phase 8.2 — Hiring Memory, Role DNA and Talent Signal integration | `OutcomeLearningService`, Outcome Insights resource (accept / reject / defer), `outcome_pattern` Hiring Memory, Role DNA history hook, Talent Signal context component, `outcomes.review` (additive grant) | 1,372 passed |
| 7 | `e3530a4` Phase 8.2 — AI outcome analysis and privacy integration | Insight AI explanation (queued, allowlisted, causal and fairness rejection), `summarize_hiring_outcomes` tool and contract case (49 tools), tighter `FAIRNESS_PATTERN` | 1,381 passed |
| 8 | `51f6fa7` Phase 8.2 — backfill, permissions, hierarchy and security hardening | `outcomes:backfill`, evaluator catch-up window, architecture guard, Manager A/B hierarchy tests | 1,396 passed |
| 9 | `97b50d3` Phase 8.2 — tests, browser coverage and final hardening | Separation re-check limited to recent changes. Serial and parallel runs, browser smokes (8.2 20/20, 8.1 12/12, 7 20/20, 6 24/24), performance measurements | 1,397 passed (serial and parallel) |
| 10 | Phase 8.2 — documentation and release freeze | `docs/phase-8-2-outcome-loop.md`, `docs/phase-8-2-security-review.md`, this plan, backlog updates, `.ai/rules` | — |

## Migrations (all additive)

- `2026_09_26_084856_create_hiring_outcome_snapshots_table`
- `2026_09_26_084857_create_hiring_outcomes_table`
- `2026_09_26_084859_create_employee_separations_table`
- `2026_09_26_084900_create_outcome_insights_table`
- `2026_09_26_091103_grant_phase_eight_two_permissions` (data: `outcomes.view`, `outcomes.manage`, `employees.separation.view`, `employees.separation.manage`)
- `2026_09_26_093436_grant_phase_eight_two_review_permission` (data: `outcomes.review`)

## Release notes for deploy

1. Run `php artisan migrate --force`.
2. Make sure the scheduler runs. `outcomes:evaluate` runs daily at 03:00.
3. Optionally run `php artisan outcomes:backfill` (dry run), review the counts, then run `php artisan outcomes:backfill --execute`. It never backfills retention.
4. Tell HR and managers:
   - The *Hiring Outcomes* dashboard is at *EDGE Intelligence → Hiring Outcomes*.
   - Separations are recorded under *Administration → Separations*.
   - Retention is observed going forward only.
   - Insights need a VP HR or CHRO decision before they affect anything.
