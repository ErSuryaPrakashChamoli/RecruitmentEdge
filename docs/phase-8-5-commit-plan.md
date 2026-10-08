# Phase 8.5: Commit Plan

Phase 8.5 is committed on `feature/sep_25_hrm` on top of the Phase 8.4 freeze (`a38a8d9e0cb341b4984e6a8bacd15030c365b737`). **Nothing has been pushed.**

**What was verified at each step:**
- Every commit is Pint-clean and PHP-lint-clean.
- The full suite was run on the final tree (1,713 tests, 19,313 assertions, parallel, `--processes=4`).
- Migrations are additive.

**One exception:** commits 3 and 4 are one change split for review. Commit 3 changes the analytics services' signatures (`timeToHire()` replaces `averageTimeToHireDays()`, and SLA compliance replaces the SLA percentage). Commit 4 moves their consumers onto them. The suite is green from commit 4 onward.

## Commits

| # | Commit | Contents |
|---|---|---|
| 1 | `1d17563` Phase 8.5 — discovery and locked decision record | Discovery report; decisions D1–D49 (approved as written) |
| 2 | `8972f32` stage-history event vocabulary (DF-9) | `StageHistoryEvent`, the `event` column, entry scopes, writers, Hiring Memory durations |
| 3 | `61e3482` metric registry, contracts, primitives and canonical metrics | `app/Services/Metrics` (28 definitions), `MetricPeriod`/`MetricScope`, the analytics / SLA / cost / daily-metrics services on the registry, set-based queries, Outcome Loop withholding / D4 / D6, snapshot v2, `closed_at`, DF-1, DF-8, DF-12 |
| 4 | `c751248` dashboard, reports, Copilot and alerts read the governed metrics | Widgets, reports and CSVs, pipeline, joining control center, Action Center, Copilot tools, `metric-card`, SEC-1 widget gates, SEC-5, DF-2/3/5/6/7/10/13/14 |
| 5 | `8b2c62a` recruiter activity authority (SEC-4) | `RecruitmentActivityService`, the `activity_backdate_days` setting, Filament paths |
| 6 | `2eb8b1a` compensation behind `compensation.view` (SEC-2, D22) | Permission and additive grant, offer table / relation managers / export, security tests |
| 7 | `21815d3` Outcome Loop re-observation (DF-11) | `isReobservable`, evaluator, cancellation path |
| 8 | `7d2dff4` frozen performance months, metric indexes, architecture guards | D48 freeze and forced recompute (audited), D45 indexes, `MetricArchitectureTest` |
| 9 | Phase 8.5 — documentation and freeze | This plan, `phase-8-5-metric-governance.md`, `phase-8-5-security-review.md`, `phase-8-5-performance.md`, backlog, `.ai/rules` |

## Existing tests changed (disclosed)

Each change makes the test assert the approved definition instead of the retired one. No assertion was removed without an equivalent replacement.

- **`RecruitmentAnalyticsServiceTest`:**
  - time to hire is a median over three hires;
  - turn-up leaves cancelled interviews out (66.7%, not 50%);
  - offer acceptance uses released offers with withdrawn excluded;
  - the retired Selection → Joining key is asserted absent;
  - sub-sample acceptance and CTC figures are asserted as withheld;
  - offer-to-join awaiting is not "did not join".
- **`RecruitmentSlaServiceTest`:** medians and compliance percentage (higher is better) replace the average and SLA percentage; sub-sample withholding; a status row never touches a leg (new test).
- **`CostPerHireServiceTest`, `RecruitmentReportsTest`:** the team's hire is on the team's requisition, so both sides share one scope; the reports page returns `MetricResult`.
- **`Identity/MetricDefectsTest` (8.4):** the same three defects, asserted on the governed metrics (acceptance over released offers, median time to hire with three hires, invalid durations counted as unknown).
- **`Ai/ToolHierarchyScopingTest`:** the source tool reports `applications`, not `sourced`.
- **`Identity/ProvisioningAndRehireTest` (8.4):** a cancelled separation's outcome is voided, then observed again (DF-11), and the void stays in history.

## Validation

- **Tests:** 1,713 tests, 19,313 assertions, parallel. The 8.4 baseline was 1,618 / 17,281, so Phase 8.5 adds 95 tests and 2,032 assertions.
- **Mutation checks** (control removed → test failed → control restored):
  - Activity: hierarchy check; incentive lock.
  - Scope: recruiter-filter scope; stage-entry scope for incentive actuals; deleted-application exclusion.
  - Security gates: export compensation gate; widget gate.
  - Outcome Loop: the DF-11 void check (a stronger mutant, "any void", was needed; the first mutant was still guarded by the separation lookup).
  - Registry: definition fingerprint; minimum-sample rule.
  - Cache: plain-array storage.
- **Browser** (Playwright, throwaway MySQL databases, no or a fake unreachable provider):

| Suite | Result | Note |
|---|---|---|
| Phase 8.5 (new) | 15/15 | Run on both the array and the database cache store |
| Phase 8.4 | 19/19 | Its original runner: MFA enforced, database sessions. The single console message is the expected refusal of the locked-property tampering attempt |
| Phase 8.3 | 17/17 | |
| Phase 8.2 | 20/20 | |
| Phase 8.1 | 12/12 | |
| Phase 7 | 20/20 | Original no-provider configuration |
| Phase 6 | 24/24 | |

- **A defect found by browser validation and fixed before the freeze:**
  - What happened: a cached `MetricResult` came back from the database cache as `__PHP_Incomplete_Class`, because the application disallows unserializing objects. The dashboard, joining control center and outcome pages then returned 500 on the second render.
  - Why the unit suite missed it: its array cache store does not serialize.
  - The fix: `MetricResult::toCache()`/`fromCache()` store plain arrays, and result details are normalised to plain data.
  - Regression test: added in `MetricCachingTest` and mutation-checked. The earlier 8.1–8.4 failures in the first browser batch were this crash; they are all green after the fix.
- **Performance:** `docs/phase-8-5-performance.md` (8.4 vs 8.5 on the same 10k and 100k data).

## Migrations (145 → 150, all additive)

See `docs/phase-8-5-metric-governance.md` §7.

## Release notes

Follow `docs/phase-8-5-metric-governance.md` §10:
- back up;
- migrate;
- run `optimize:clear`;
- run `queue:restart`;
- run `npm run build`;
- tell users what the numbers now mean.
