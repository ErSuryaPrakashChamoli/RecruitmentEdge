# Git Release-Line Reconciliation

**For:** the release owner and whoever maintains the repository's branches.

**Date:** 2026-10-06. **Method:** read-only. The tools were `git merge-base`, `rev-list`, `cherry`, `diff-tree` and `show`, plus `git ls-remote` against `origin` (no fetch). One test check was also run (§6).

**Nothing was mutated:** no merge, cherry-pick, rebase, reset, push, branch change or fast-forward.

---

## 1. Executive conclusion

**Canonical branch: `feature/production-readiness` @ `589c2f0`.** The local branch and `origin/feature/production-readiness` point at the same commit, as does the tag `backup-production-readiness-2026-10-06`.

| Question | Answer (evidence in §2–§8) |
|---|---|
| A. Is it the complete accumulated line? | **Yes.** `main`, `feature/sep_25_hrm` and all seven SaaS branches are strict ancestors. The history from `main` is linear: 232 commits, no merge commits |
| B. Does an old feature branch hold unique **required** code? | **No.** The only feature branch with unique commits is `feature/sep_21_demo` (2 commits: a demo environment). That code is not on the release line and is not release code. It is unique, though, so it needs an owner decision before archiving |
| C. Do the hotfixes hold security behaviour absent from the release line? | **No.** Every delete-family ability the hotfixes define is equal or **stricter** on the release line. `CandidateJoiningPolicy::create` is identical. The hotfix tests pass on HEAD (6 / 6) |
| D. Can `main` be fast-forwarded later? | **Yes.** `main` (`9cba8e3`) is an ancestor, with 0 commits of its own and 232 on the release line |
| E. Branch roles | §9 |
| F. `origin/main`, `origin/production`, `origin/test` | **Older ancestors, not divergent:** all three are `9cba8e3`, 0 commits of their own. **What production actually runs is not established by Git** (§8) |

**One caveat on the release candidate:**
- **The tip has untested code.** It contains `589c2f0` ("last final on oct 6"), an application change made **after** the last complete regression (on `cf9082c`). The change is `AdminPanelProvider`: the notifications bell renders only inside a tenant, which fixes a 500 on the MFA enrolment page. It also adds a test to `MfaTest` and the rule `.ai/rules/auth.md`.
- **Only its own test file has run:** `MfaTest` passes 10 / 10. The complete regression has not been repeated.
- **Consequence:** a release commit is not yet frozen. Gate 0 requires a complete regression on whichever commit is approved.

**Production go-live: NOT APPROVED** (production readiness: NO-GO).

---

## 2. Verified branch graph

Every arrow is verified with `git merge-base --is-ancestor`. The release line has **no merge commits** since `main`.

```
aaf6c66  feature/aug_28_stage_1
  │ 2
cfbab3c  feature/sep_2_dockerfile, feature/sep_8_check
  │ 3
897008f  feature/sep_12_fixes ─────────┐
  │ 1                                  │ 2 commits (diverged)
1e2518e  feature/sep_21_branch_setup,  5c9ce85  feature/sep_21_demo = origin/feature/sep_21_demo
         feature/sep_21_update_without_demo,
         feature/sep_22_requistions
  │ 1
9cba8e3  main = production = test = feature/sep_22_requistion
         = origin/main = origin/production = origin/test = origin/feature/sep_22_requistion
  │                    ├── 2fab3fd  hotfix/filament-delete-authorization      (1 commit, diverged)
  │                    │     └── 599f0c5  hotfix/p810-production-authorization (2 commits, diverged)
  │ 180
3fb40d6  feature/sep_25_hrm (Phase 8.11; its RC 226bc7d is inside)
  │ 6
0e8d865  feature/saas-1-tenant-foundation
  │ 5
869d89a  feature/saas-2-identity-access
  │ 5
dc45bee  feature/saas-3-provisioning-entitlements
  │ 3
9a3d04b  feature/saas-4-billing
  │ 1
3551400  feature/saas-5-platform-control
  │ 7
e57ddaa  feature/saas-6-api-integrations
  │ 16
54e551b  feature/saas-7-scale-reliability
  │ 2
95f85d5  production-readiness code closure (code)
  │ 1
cf9082c  tested commit (complete regression 2026-10-06)
  │ 6    (docs; then 589c2f0: an application change)
589c2f0  feature/production-readiness = origin/feature/production-readiness
         = tag backup-production-readiness-2026-10-06
```

The numbers between nodes are commits. **Remote state** was checked with `git ls-remote origin` on 2026-10-06; the local remote-tracking refs match it. **No tag exists on the remote**; the safety tag is local.

---

## 3. Branch matrix

Unique commits are counted against `feature/production-readiness` (FPR).

| Branch | HEAD | Relationship | Unique on branch / on FPR | Unique required code? | Status | Recommendation |
|---|---|---|---|---|---|---|
| `feature/production-readiness` | `589c2f0` | — | — | — | Canonical | Keep; development and release-candidate line |
| `origin/feature/production-readiness` | `589c2f0` | Same | 0 / 0 | — | Pushed copy | Keep |
| `main` | `9cba8e3` | Ancestor | 0 / 232 | No | Behind | Fast-forward later, on approval (§10) |
| `origin/main` | `9cba8e3` | Ancestor | 0 / 232 | No | Behind | As `main` |
| `production` (local) | `9cba8e3` | Ancestor | 0 / 232 | No | Behind | Keep until the production line is decided (BU-O13) |
| `origin/production` | `9cba8e3` | Ancestor | 0 / 232 | No | Behind | As above; see §8 |
| `test` (local) / `origin/test` | `9cba8e3` | Ancestor | 0 / 232 | No | Behind | Keep until the environment strategy is decided |
| `feature/sep_25_hrm` | `3fb40d6` | Ancestor | 0 / 52 | No | Fully contained | Historical; archive later |
| `feature/saas-1-tenant-foundation` | `0e8d865` | Ancestor | 0 / 46 | No | Included | Historical; archive later |
| `feature/saas-2-identity-access` | `869d89a` | Ancestor | 0 / 41 | No | Included | Historical; archive later |
| `feature/saas-3-provisioning-entitlements` | `dc45bee` | Ancestor | 0 / 36 | No | Included | Historical; archive later |
| `feature/saas-4-billing` | `9a3d04b` | Ancestor | 0 / 33 | No | Included | Historical; archive later |
| `feature/saas-5-platform-control` | `3551400` | Ancestor | 0 / 32 | No | Included | Historical; archive later |
| `feature/saas-6-api-integrations` | `e57ddaa` | Ancestor | 0 / 25 | No | Included | Historical; archive later |
| `feature/saas-7-scale-reliability` | `54e551b` | Ancestor | 0 / 9 | No | Included | Historical; archive later |
| `hotfix/filament-delete-authorization` | `2fab3fd` | Diverged (base `9cba8e3`) | 1 / 232 | No (§6) | Superseded | Reference; keep until production's line is identified (§9) |
| `hotfix/p810-production-authorization` | `599f0c5` | Diverged (base `9cba8e3`) | 2 / 232 | No (§6) | Superseded | Same |
| `feature/sep_21_demo` / `origin/feature/sep_21_demo` | `5c9ce85` | Diverged (base `897008f`) | 2 / 234 | Not release code; **unique demo tooling** | Unmerged | **Not safe to archive** without an owner decision |
| `feature/aug_28_stage_1` | `aaf6c66` | Ancestor | 0 / 239 | No | Contained | Archive later |
| `feature/sep_2_dockerfile`, `feature/sep_8_check` | `cfbab3c` | Ancestor | 0 / 237 | No | Contained | Archive later |
| `feature/sep_12_fixes` | `897008f` | Ancestor | 0 / 234 | No | Contained | Archive later |
| `feature/sep_21_branch_setup`, `feature/sep_21_update_without_demo`, `feature/sep_22_requistions` | `1e2518e` | Ancestor | 0 / 233 | No | Contained | Archive later |
| `feature/sep_22_requistion` / `origin/feature/sep_22_requistion` | `9cba8e3` | Ancestor | 0 / 232 | No | Contained | Archive later |

---

## 4. `feature/sep_25_hrm` conclusion

`git rev-list --left-right --count feature/sep_25_hrm...feature/production-readiness` = **0 / 52**.

**`feature/sep_25_hrm` is fully contained in `feature/production-readiness`.**
- **Nothing needs to be recovered.**
- **Phase 8.11:** its release candidate `226bc7d` is an ancestor too.

---

## 5. SaaS-1 through SaaS-7 conclusion

| Phase | Branch tip | Result |
|---|---|---|
| SaaS-1 | `0e8d865` | **INCLUDED** |
| SaaS-2 | `869d89a` | **INCLUDED** |
| SaaS-3 | `dc45bee` | **INCLUDED** |
| SaaS-4 | `9a3d04b` | **INCLUDED** |
| SaaS-5 | `3551400` | **INCLUDED** |
| SaaS-6 | `e57ddaa` | **INCLUDED** |
| SaaS-7 | `54e551b` | **INCLUDED** |

**No exception:**
- Each is a strict ancestor of FPR, with 0 commits of its own.
- They form one linear chain (§2).

---

## 6. Hotfix reconciliation

`git cherry` marks both hotfix commits `+`: no textually identical patch exists on FPR. **That reflects different implementations, not missing behaviour.** Behaviour was compared method by method.

### `hotfix/filament-delete-authorization` — `2fab3fd`

| | |
|---|---|
| Commit | `2fab3fd` "Security hotfix: close Filament's missing-policy-method delete bypass" (2026-09-27). It touches 23 policies, adds `app/Policies/Concerns/ForbidsDeletion.php`, and adds `DeleteAuthorizationTest` and `PolicyActionCoverageTest` |
| Security purpose | SEC-1 (Phase 8.6): Filament treated a missing policy method as allowed, so delete, bulk-delete, force-delete and restore were reachable without a rule |
| Current equivalent | Development-line commits `3e51819` (strict authorization; fail-closed `Gate::before` → `policyLacksAbility()` denies any ability a model's policy lacks) and `dcff76e` (`ForbidsDeletion`) |
| Comparison | See the four points below the table |
| Status | **Fully superseded:** same or stricter behaviour |
| Action | None. Keep the branch for reference until production's line is identified (BU-O15) |

**Comparison details:**
- **`ForbidsDeletion.php`:** byte-identical (SHA-256 `692675ad…2c6b` at `2fab3fd` and HEAD). It is used by `CandidateApplicationPolicy`, `CandidateJoiningPolicy`, `CandidatePolicy`, `InterviewPolicy` and `OfferPolicy`, on both sides.
- **The other policies** (62 delete-family method bodies compared):
  - **31 identical.**
  - **27 differ, all stricter on FPR.** Permission checks (`settings.manage`, `incentives.configureRules`, `users.manage`) became `return false` for bulk, force and restore. Single deletes gained conditions: not oneself (Employee), not referenced (offer-letter template), not used (incentive rule), within hierarchy (Employee restore).
  - **1 rewritten with equal behaviour:** `RecruitmentDailyTargetPolicy::delete` uses `isVisibleTo()` instead of `isInScope()`. The rule is the same: view-all allowed; department or designation targets refused; otherwise the employee must be in the user's hierarchy. On FPR it also runs inside tenant scoping.
  - **3 absent from `EmployeePolicy`:** `deleteAny`, `forceDeleteAny`, `restoreAny`. The hotfix's versions `return false`, and the fail-closed gate on FPR also denies them. Same behaviour.
- **Tests:** `PolicyActionCoverageTest` is byte-identical on FPR. `DeleteAuthorizationTest` has the same assertions; a comment changed and three fixtures are wrapped in `lifecycleFixture(...)`, which the later lifecycle guards require.

> **Correction to an earlier record:** `production-readiness-code-closure.md` PRC-10 says the candidate had `DeleteAuthorizationTest` identical and `PolicyActionCoverageTest` adapted. It is the reverse. The conclusion does not change.

### `hotfix/p810-production-authorization` — `599f0c5` (on top of `2fab3fd`)

| | |
|---|---|
| Commit | `599f0c5` "Security hotfix (production line): explicit CandidateJoiningPolicy::create (SEC-86-I-01)" (2026-10-03). It changes `CandidateJoiningPolicy.php` and adds `JoiningCreateAuthorizationTest` |
| Security purpose | SEC-86-I-01: creating a joining record by hand must require `joining.confirm` |
| Current equivalent | `CandidateJoiningPolicy::create(User $user): bool { return $user->can('joining.confirm'); }` — identical body on FPR. `JoiningCreateAuthorizationTest` is on FPR (added in the code closure); it is identical except for a 3-line provenance comment |
| Evidence | The three hotfix test files (`DeleteAuthorizationTest`, `PolicyActionCoverageTest`, `JoiningCreateAuthorizationTest`), run on HEAD `589c2f0` today: **6 / 6 passed, 25 assertions** |
| Status | **Fully superseded** |
| Action | None. Keep the branch for reference until production's line is identified |

**HOTFIX STATUS:**
- **`hotfix/filament-delete-authorization`:** fully superseded.
- **`hotfix/p810-production-authorization`:** fully superseded.
- **Genuinely missing security behaviour:** none.
- **Selective code migration:** not required.

---

## 7. Main conclusion

| | Left only | Right only |
|---|---|---|
| `main...feature/production-readiness` | 0 | 232 |
| `origin/main...origin/feature/production-readiness` | 0 | 232 |

`main` is an ancestor of `feature/production-readiness`. There are no merge commits on the line.

**main can be fast-forwarded to the approved release commit later.** That will be a clean fast-forward, as long as nothing is committed to `main` meanwhile. **It was not performed.**

---

## 8. Production and test branch conclusion

### Git branch relationship

| Pair | Left only / right only |
|---|---|
| `origin/production...origin/main` | 0 / 0 (the same commit, `9cba8e3`) |
| `origin/test...origin/main` | 0 / 0 (the same commit) |
| `origin/production...origin/feature/production-readiness` | 0 / 232 |
| `origin/test...origin/feature/production-readiness` | 0 / 232 |

`origin/production` and `origin/test` are **older ancestors, not divergent**. They do not contain the delete-authorization fix: `9cba8e3` has neither `ForbidsDeletion` nor `CandidateJoiningPolicy::create`.

### Actual production deployment certainty

**UNKNOWN.**
- **A branch is not a deployment:** a branch named `production` shows what was pushed under that name, not what runs.
- **No production evidence exists in this repository** (`docs/production-bring-up-stage-2b-production-facts.md`).
- **Settling it needs Infrastructure/DBA:** the read-only collection package there (fingerprint, migration count, Laravel version).

---

## 9. Recommended branch policy

| Role | Branch | Note |
|---|---|---|
| Canonical development / release-candidate branch | `feature/production-readiness` | The **release candidate commit** must be frozen and fully regressed first; the tip `589c2f0` has an untested-by-full-regression change (§1) |
| Main branch | `main` | Fast-forward to the approved release commit only, on owner approval (BU-O13) |
| Production branch | `production` / `origin/production` | **Owner decision (BU-O13):** whether `production` continues to exist, and whether it tracks `main` or the release commit. It stays as is until then |
| Test branch | `test` / `origin/test` | Owner/infrastructure decision with the staging strategy |
| Historical (fully contained; may be archived later) | `feature/sep_25_hrm`, `feature/saas-1…7`, `feature/aug_28_stage_1`, `feature/sep_2_dockerfile`, `feature/sep_8_check`, `feature/sep_12_fixes`, `feature/sep_21_branch_setup`, `feature/sep_21_update_without_demo`, `feature/sep_22_requistions`, `feature/sep_22_requistion` | 0 unique commits each. Archiving loses nothing; a tag per tip is optional, for naming |

**Do not delete yet:**

| Branch | Why |
|---|---|
| `hotfix/filament-delete-authorization` | Superseded in code, but production's running line is unknown. If it turns out to be `9cba8e3` or a hotfix build, the hotfix is the reference for what production lacks or has |
| `hotfix/p810-production-authorization` | Same; also a documented interim release option (Phase 8.11 §2) |
| `feature/sep_21_demo` / `origin/feature/sep_21_demo` | Holds unique, unmerged demo-environment work (`SetupDemo` command, demo seeders, demo login view, demo tests). It needs an owner decision: bring forward (a code change) or abandon |
| `main`, `production`, `test` (local and remote) | Release and environment roles are still open (BU-O13) |

---

## 10. Exact future release operation

> **NOT TO BE EXECUTED NOW.** Production readiness is NO-GO.

**Prerequisites:**
- BU-O13/O14 decided;
- the release commit frozen;
- a complete regression on that commit (Gate 0).

**Then:**

```
git checkout main
git merge --ff-only <approved release commit on feature/production-readiness>
```

When the approved commit is the branch tip:

```
git checkout main
git merge --ff-only feature/production-readiness
```

**Notes:**
- `--ff-only` refuses if `main` has gained commits meanwhile. That is the intended safety stop.
- Pushing `main`, and updating `production` if the owner keeps it, are separate decisions under BU-O13.

---

## 11. Safety verification

| Check | Result |
|---|---|
| HEAD before and after | `589c2f0f615952f1c4fed96a45a9e170ed877904` (unchanged) |
| Safety tag `backup-production-readiness-2026-10-06` | Resolves to `589c2f0` (lightweight tag; local only, not on `origin`) |
| Working tree | Clean of tracked changes. **Two untracked files:** the pre-existing `s-2026-10-06` (a saved `git log --graph` capture, found at Phase 1 and left untouched on the user's instruction) and this report (left uncommitted, as requested) |
| Merges performed | None |
| Cherry-picks performed | None |
| Rebases performed | None |
| Resets performed | None |
| Pushes performed | None |
| Branches created, renamed or deleted | None |
| Fetch performed | None (`git ls-remote` only) |
| Application code, tests, migrations, configuration modified | None |
| Tests run (read-only, in-memory) | Hotfix tests 6 / 6; `MfaTest` 10 / 10 |
