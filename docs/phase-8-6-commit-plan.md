# Phase 8.6 Commit Plan

**Branch:** `feature/sep_25_hrm`. **Base:** `dcff76e` (Phase 8.5 freeze `05c922a` + the approved security containment).

**Git rules followed:** nothing pushed; no history rewritten, reset or squashed.

## Commits

| # | Commit | Content |
|---|---|---|
| 1 | `42156f2` | Phase 8.6 discovery and decision record (documents) |
| 2 | `88fbcf6` | Phase 8.7 discovery only: four documents, nothing implemented |
| 3 | `44adbfe` | Audit foundation (reason, restored / force_deleted / archived, redaction, audit filters, three newly audited models) and master-data lifecycle (service, actions, policies, withTrashed, active-reference guard and pickers, archive-aware seeders, sources by code, interviewers, hiring-snapshot/3) |
| 4 | `cc5ac9e` | Issued offer letters and offer-letter template versions |
| 5 | `69e7d2e` | Settings history, read-only raw settings, `sla.leg_compliance` v2 and as-of time-to-hire target |
| 6 | `f0cdb8f` | Incentive pricing snapshots, use-lock, slab audit, effective-range and overlap validation, freeze catch-up |
| 7 | `a6aaffa` | Two flaky tests fixed (test-only) |
| 8 | `faa6b9e` | Pipeline template versions and governed re-application |
| 9 | `bc52957` | Automation and communication configuration governance |
| 10 | `3e51819` | Strict authorization, fail-closed gate, explicit policies |
| 11 | `403fef4` | Configuration fingerprint and read-only `governance:audit` |
| 12 | `05fd14f` | "(archived)" marker on existing records |
| 13 | `b8adf6e` | `.ai/rules` for the new conventions |
| 14 | `14e7416` | Fast as-of setting lookup (binary search) for the SLA metric — performance, no semantic change |
| 15 | (this commit) | Implementation, security, performance, commit-plan and freeze documents; backlog |

## Grouping notes

- **Commit 3** combines two groups: the `Auditable` changes serve both the audit foundation and the `archived` action of master data.
- **Files carrying several decisions** were committed with the later group: `RecruitmentIncentiveRule` (active-master-data guard + use-lock) and `RecruitmentDailyTarget` (guard + overlap). Commit 3 therefore applies the master-data guard to seven models, and commit 6 to the remaining two.
- **Test updates** reflecting approved behaviour changes are committed with the change that required them.

## Deployment

- **This branch:** see `phase-8-6-implementation.md` §7 (eight additive migrations, `optimize:clear`, `queue:restart`, `governance:audit`).
- **Production:** the security hotfix is a separate, manual release action (D8.6-030), also in §7.
