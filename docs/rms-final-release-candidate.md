# RMS Final Release Candidate — Phase 8.10 Final Release Readiness

**For:** the project owner, Security, Operations and Engineering.

**Recruitment Edge — The Hiring Operating System.**

| | |
|---|---|
| Branch | `feature/sep_25_hrm` |
| Baseline (verified, clean) | `d87a607` |
| Final head | see `phase-8-10-verification.md` §14 |
| Pushed / merged / deployed | **No / No / No** |
| Production readiness | **Not declared.** Production is not verified; Phase 8.11 does that. |

**Scope.** This round is the final review of the application release.
- It fixed six known findings that are genuine release blockers (§3).
- It proved the hiring lifecycle end to end.
- It ran every suite on the real stack.
- Everything else received a final disposition (§4): **nothing is left simply "open"**.
- Non-blocking work moved to `post-rms-backlog.md`.

**Disposition classes:**
- A: Release blocker (fixed this round: **FIXED ON BRANCH, NOT DEPLOYED**)
- B: Required before release
- C: Owner / infrastructure action
- D: Post-RMS backlog
- E: Accepted / documented risk
- F: Duplicate
- G: Superseded

## 1. RMS final completion matrix

| Area | Status | Blocker | Action |
|---|---|---|---|
| Requisition and approval | PASS: maker-checker (requester cannot approve), status workflow, scoping | — | — |
| Job publishing and careers site | PASS: only approved, open requisitions publish; anonymous applications never change an existing candidate | — | — |
| Candidate and application | PASS: scoped picker and server checks (SEC-004, SEC-006), duplicate detection | — | — |
| Screening, interview, feedback, selection | PASS: service-owned transitions, feedback by the assigned interviewer, selection rule | — | **PM-01 time zones: owner decision D8.10-006 (§6)** |
| Offer, release, acceptance | PASS: release needs `offers.release`; acceptance creates the joining in the same transaction | — | Offer governance is D (D8.10-008) |
| Joining and Mark Joined | PASS: joining only for an accepted offer; Joined only on it (DI-04) | — | Legacy hand-made joinings: D (remediation procedure later) |
| Employee conversion | PASS: `employees.convert`, Joined only, once only | — | — |
| Hiring outcome and retention observation | PASS: outcomes from the joining record; 30 / 90 / 180-day status checkpoints | — | — |
| Referrals | PASS: Joined only on the joining record; locked sync (DI-02) | — | — |
| Incentives | PASS: lifecycle preconditions, one per occurrence (DI-01); no self-approval (SEC-008); approval trail | — | Payment reconciliation and retention checks: D (D8.10-009) |
| Communications and notifications | PASS: consent and suppression, send-time guard, queued and encrypted security mails | — | Mail retries (OP-20): D |
| Candidate portal | PASS: signed single-use links pinned to APP_URL (SEC-001), step-up, scoped portal | — | APP_URL and trusted hosts: C (D8.10-023) |
| Documents and files | PASS: private disk, signed and audited downloads, raster-only photos (SEC-002) | — | `/storage` headers: C/D |
| Audit | PASS: status and stage histories, Auditable lifecycle models, approval trails, refusals audited | — | Requisition and application field audit: D |
| Reporting and analytics | PASS: governed metrics (8.5), hires from joining records | — | — |
| AI / Copilot | PASS: scoped tools, projector and egress guard, approval with every parameter (AI-01), no image exfiltration (AI-03), comparison scoped (AI-11) | — | AI provider terms: C (AI-15) |
| Automation | PASS: human-authored rules; decision stages cannot be automated | — | AI-04: D (D8.10-013) |
| Exports | PASS: capped, owner-only, audited, formula-safe | — | — |
| Queues, workers, scheduler | PASS at application level: topology tests, drain check, heartbeats | — | Docker execution: C (D8.10-005) |
| Authentication and identity | PASS: staff MFA, lockout, credential lifecycle, candidate sessions (8.4, 8.8) | — | — |
| Authorization and hierarchy | PASS: fail-closed gate, strict policies, hierarchy scope; no open escalation in the application release | — | — |
| Deployment artefact (Docker image) | Code fixed (PHP 8.5.11 pinned) | **Not built: no container builder** | C (D8.10-005, OP-01) |
| Backup and restore | Proven on the development host only | No production backup | C (D8.9-007…010, 028) |
| Production configuration | Checklist exists | Production facts unknown | C (D8.9-026, D8.10-023) |

## 2. Release-critical AI guarantees

| # | Guarantee | Result | Evidence |
|---|---|---|---|
| 1 | AI cannot bypass authorization | PASS | Tools use `ScopesToHierarchy`; `ActionExecutor::approve` re-checks permission, requester, expiry and the authority fingerprint |
| 2 | AI cannot reach candidates outside scope | PASS | Hierarchy-scoped tools; `compare_candidates` application scope fixed (AI-11) |
| 3 | AI cannot leak protected PII | PASS | AiProjector, AiEgressGuard and AiReferenceResolver unchanged; image exfiltration closed (AI-03) |
| 4–7 | No autonomous hire, reject, select or employment decision | PASS | All 6 write tools require approval. No AI tool creates offers, joinings or conversions. Automation cannot target selection, offer, joining or rejection stages. |
| 8 | Write actions require human approval | PASS | `AiRiskLevel::requiresConfirmation` for Write, External and HighImpact |
| 9 | Approved parameters cannot be replaced before execution | PASS | Arguments stored at proposal, never rewritten; atomic claim; execution uses the stored arguments, now shown in full on the card (AI-01) |
| 10 | Projector, egress guard and reference resolver intact | PASS | AI and privacy suites pass |

## 3. Release blockers fixed in this round (FIXED ON BRANCH, NOT DEPLOYED)

| Finding | Severity | Why it blocked the release | Fix | Commit |
|---|---|---|---|---|
| P810-AI-01 | High | The approver could not see the stage, reason, schedule or message being approved ("humans decide" without seeing) | Every stored argument shown on the approval card; contract test over every approval-required tool | `30f252d` |
| P810-AI-03 | Medium | A zero-click PII channel: a prompt-injected image URL carries a resolved candidate name | AI output renders images as their alt text | `0fff14e` |
| P810-AI-11 | Low | Breaks guarantee 2: another team's application reached the model | Comparison application scoped | `d6b8b07` |
| P810-SEC-006 | Medium | Any staff user could read every candidate's name and mobile by application id | Labels resolve within scope | `008f2d3` |
| P810-SEC-002 | Medium | Stored XSS from any staff user to an administrator who opens the photo (escalation path) | Raster-only photos | `f0a018a` |
| P810-SEC-008 | Medium | An approver could approve, adjust or pay their own incentive (financial integrity) | Beneficiary refused in the policy and the service | `049799c` |

Plus the end-to-end lifecycle test (`10fe3d9`). There is no migration and no dependency change.

## 4. Final disposition of every Phase 8.10 finding

### 4.1 Security (P810-SEC)

| ID | Severity | Current state | Release impact | Disposition |
|---|---|---|---|---|
| SEC-001 | High | FIXED ON BRANCH (`6ead373`) | None for the application; needs production values | A (fixed) + C (D8.10-023) |
| SEC-002 | Medium | FIXED ON BRANCH (`f0a018a`) | None | A (fixed); `/storage` headers D |
| SEC-003 | Low | Open | None | D |
| SEC-004 | High | FIXED ON BRANCH (`88df53a`) | None | A (fixed) |
| SEC-005 | Low | Open (custom roles only) | None | D |
| SEC-006 | Medium | FIXED ON BRANCH (`008f2d3`) | None | A (fixed) |
| SEC-007 | Low | Open | None | D |
| SEC-008 | Medium | FIXED ON BRANCH (`049799c`) | None | A (fixed) |
| SEC-009 | Low | Partly fixed (`88df53a`) | None | D (remainder) |
| SEC-010 | Low | Open (conditional; last-CHRO lock holds) | None | D |
| SEC-011 | Low | Open (E-06) | None | D |
| SEC-012 | Info | Open (product rule) | None | D |
| SEC-013 | Info | Open | None | D |
| SEC-014 | Low | Open | None | D |
| SEC-015 | High (advisory) | FIXED ON BRANCH (`ae48029`); production line still has the old versions | Fixed by deploying the release candidate | A (fixed) + C |
| SEC-016 | Low | Open | None | D |
| Production delete-authorization gap (P89-OPS-012, SEC-86-I-01) | Critical, production only | Closed on the branch (fail-closed gate); Release A prepared | **Live in production until a release is deployed** | C (D8.10-002 / 003 / 021) |

### 4.2 AI (P810-AI)

| ID | Severity | Disposition |
|---|---|---|
| AI-01 | High | A: FIXED ON BRANCH (`30f252d`). Approval friction (typed confirmation, second approver) is D under D8.10-012. |
| AI-02 | Medium | D. The harmful outcomes are closed: no zero-click exfiltration (AI-03) and every write needs an informed approval (AI-01). |
| AI-03 | Medium | A: FIXED ON BRANCH (`0fff14e`). Links are D. |
| AI-04 | Medium | D (D8.10-013). Automation cannot reach decision stages. |
| AI-05 | Medium | D (D8.10-010). Stage moves need approval, and the stage is now shown. |
| AI-06 … AI-10 | Low | D |
| AI-11 | Low | A: FIXED ON BRANCH (`d6b8b07`) |
| AI-12 | — | F (= SEC-88-26) |
| AI-13, AI-14 | Info | D |
| AI-15 | Info | C: provider terms and model choice before AI is enabled in production (D8.10-014) |

### 4.3 Data integrity (P810-DI)

| ID | Severity | Disposition |
|---|---|---|
| DI-01, DI-02, DI-04 | High | FIXED ON BRANCH (`2404763` / `198bbf3`, `1a40e6c`, `612c7a9`) |
| DI-02-01 (implementation) | Medium | FIXED ON BRANCH (`1a40e6c`) |
| DI-01-01 (implementation) | Info | FIXED ON BRANCH (`198bbf3`) |
| DI-03, DI-05 | Medium | D (D8.10-010). Since DI-01/02/04, a stage-only "Joined" prices nothing, pays no referral bonus, records no outcome and cannot be converted, and filled openings count joining records. |
| DI-06 | Medium | D (with D8.10-006) |
| DI-07, DI-08 | Medium | D (D8.10-009 (d)(e)). Compensating controls: payment needs `incentives.pay`, nobody pays their own (SEC-008), and maturity leads to human verification. |
| DI-09 | Medium | D (D8.10-017) |
| DI-10 | Low | E: matches the governed metric specification; a change needs D8.10-018 |
| DI-11, DI-12, DI-13, DI-15, DI-16 | Low | D |
| DI-14, DI-17 | Info | D |

### 4.4 Operations (P810-OP)

| ID | Severity | Disposition |
|---|---|---|
| OP-01 | High | C: the image must be built and tested on a Docker-capable runner (code fixed in `bd32662`) |
| OP-02 | High | C: rehearsal on a production copy, or owner acceptance of the development-host rehearsal (D8.10-003, D8.9-026) |
| OP-03 | Medium | FIXED ON BRANCH (procedure and `queue:drain-status`); execution under Docker is C |
| OP-04 | Medium | D |
| OP-05, OP-06, OP-07, OP-09 | Medium | C: capacity sizing, binlog policy, log rotation, image registry |
| OP-08 | Medium | C (CI, D8.10-005) |
| OP-11, OP-12, OP-20 | Medium | D |
| OP-10, OP-13, OP-15, OP-16, OP-18, OP-19 | Low | D |
| OP-14 | Low | C: set `DB_PASSWORD` from the secret store (production checklist) |
| P810-A4-01 | Info | E: documented, restore is the rollback |
| P810-OP-03-01, P810-A1-01 | Low | FIXED ON BRANCH |

### 4.5 Product maturity (P810-PM)

| ID | Severity | Disposition |
|---|---|---|
| **PM-01** | High | **C: owner decision D8.10-006 before self-scheduling or calendar/Zoom sync is used in production** (§6). Manual scheduling shows candidates the time the recruiter entered. The fix follows the decision. |
| PM-02, PM-04, PM-05, PM-06 | High | D (D8.10-007 / 016 / 015 / 008). The manager-role workflow (requisition owner and approver, scoped dashboards) and assigned-interviewer feedback work today. |
| PM-07 … PM-21 | Medium | D |
| PM-26 | Low | D |
| PM-03, PM-11, PM-13, PM-22 … PM-25 | — | F (known items, counted elsewhere) |

### 4.6 Documentation (P810-DOC)

| ID | Disposition |
|---|---|
| DOC-01 (Medium), DOC-02, DOC-04, DOC-05, DOC-06 | D |
| DOC-03 | F (known Phase 8.7 item) |
| DOC-07 | G for the deploy-drain part (runbook rewritten in Workstream A); D for the remainder |

### 4.7 Carried-forward known items (not counted in the 8.10 discovery)

| Item | Disposition |
|---|---|
| SEC-88-05, SEC-88-07, SEC-88-14 | E (already accepted, B, in Phase 8.8) |
| SEC-88-10 (no trusted proxy) | C (depends on the production front end; D8.10-023) |
| SEC-88-02 and the other SEC-88 deferrals; P86-BACKLOG-004; P88-BACKLOG-002; P89-SEC-006 / 008 / 010 / 012 residuals | D |
| `docs/backlog.md` Open items | D, except items marked **production action** (C) |

### 4.8 Found in this round (not in the discovery counts)

The whole suite was run on MySQL 8.4 for the first time (TD-15). All three findings predate Phase 8.10.

| ID | Severity | Finding | Disposition |
|---|---|---|---|
| P810-RC-01 | Low | `AutomationRuleService::update` compares the stored configuration with `!==`, and MySQL returns JSON keys reordered. A name- or description-only edit is therefore treated as a configuration change: it asks for a reason and writes a new version. Stricter, not weaker, and no data changes. | D (compare key-order-insensitively) |
| P810-RC-02 | Info | 7 tests assert stored JSON arrays with `toBe` (key order) or rely on tie order, so they fail only on MySQL: feedback ratings ×2, interviewer import counts, automation conditions, archive audit, memory facts, duplicate tie order. Product reads by key; at most the display order differs. | D (MySQL CI job, H3) |
| P810-RC-04 | Low | The Outcome dashboard's default "to" date is the UTC date, while report periods are in the business time zone (IST). From 00:00 to 05:30 IST, outcomes observed after IST midnight are outside the default view until 05:30 IST (or until a date is chosen), and `OutcomeHierarchyTest` fails in that window (reproduced at 18:33 UTC; passes at 10:00 UTC). Since Phase 8.2. | D (default to the business date) |
| P810-RC-03 | Info | Concurrency harness: tests share one database per run, and the department factory draws 3-letter codes, so DQ-001's setup collided once (`DEPT-KUV`). The re-run passed 11 / 11. | D (deterministic fixture codes) |

## 5. Prepared production authorization release (Release A), re-verified

| Question | Answer |
|---|---|
| Content | `9cba8e3` + `2fab3fd` + `599f0c5` (unchanged since its verification) |
| Closes | The Phase 8.6 SEC-1 delete bypass: a missing policy method allowed Delete, ForceDelete and Restore. Also SEC-86-I-01 (explicit joining `create`). |
| Files | 26: 23 under `app/Policies`, 3 tests; +454 / −0 |
| Migrations / routes / dependencies / config | None / none / none / none |
| Behaviour outside authorization | None (policy methods and tests only) |
| Evidence | The four hotfix tests fail 4 / 4 on `9cba8e3` and pass on `599f0c5`; the production-line suite passes 646 / 646 (`phase-8-10-verification.md` §6) |
| State | Prepared only: not pushed, not merged, not deployed. If the release candidate is deployed instead, Release A is not needed (D8.10-002 / 003). |

## 6. Owner and infrastructure actions (C): release prerequisites, separate from the application

1. **Container build (D8.10-005, OP-01):** build and test the image on a Docker-capable runner. The image has never been built; there is no container builder here.
2. **Release strategy (D8.10-002 / 003 / 021):** deploy the release candidate (closes the production Critical, SEC-001/004/015 and DI-01/02/04), or Release A first. Choose the downtime window. Accept the development-host rehearsal or provide a production copy (OP-02).
3. **Production facts (D8.9-026, D8.10-023):**
   - `APP_URL` (the exact public origin);
   - `APP_TRUSTED_HOSTS` (including `localhost` for the health check);
   - Apache `ServerName` with a default reject vhost;
   - the reverse proxy and TLS termination (trusted proxies, SEC-88-10);
   - calendar and Zoom redirect URIs, which must match `APP_URL`.
4. **Backup policy (D8.9-007, 008, 009a–d, 010, 028):** RTO, RPO, frequency, retention, off-host storage, key custody, DR, restore cadence. Then take a production backup before the release.
5. **Production configuration checklist** (`docs/runbooks/production-environment.md`): secrets including `DB_PASSWORD` (OP-14), capacity (OP-05), binlog (OP-06), log rotation (OP-07), image registry (OP-09).
6. **Interview time zones (PM-01, D8.10-006):**
   - Until it is decided, schedule interviews manually and leave calendar and Zoom sync unconfigured.
   - Self-scheduling confirmations print the stored true-UTC time (for example 04:30 for a 10:00 IST slot).
   - Calendar and Zoom sync send a manually entered time as UTC, which is 5 h 30 off for IST.
7. **AI in production (AI-15, D8.10-014):** accept the provider's data-handling terms and the model choice before enabling an AI provider. Without a key the Copilot degrades safely.

## 7. Final release gate

| Gate | Result |
|---|---|
| Core recruitment lifecycle works end to end | ☑ `HiringLifecycleEndToEndTest` (passes on SQLite and on MySQL 8.4) plus the stage suites and the browser smokes |
| No Critical security issue remains (application release) | ☑ (the production Critical is closed by deploying; C) |
| No release-blocking High issue remains | ☑ (AI-01 fixed; PM-01 is C with an operational control) |
| No release-blocking data-integrity issue remains | ☑ |
| No privilege escalation in the application release | ☑ (SEC-002, 004, 006, 008 fixed) |
| AI cannot bypass authorization | ☑ |
| AI cannot make autonomous employment decisions | ☑ |
| Offer → acceptance → joining → employee conversion intact | ☑ |
| Incentive and referral integrity intact | ☑ |
| Audit works for critical lifecycle actions | ☑ |
| Required tests pass | ☑ Full suite (SQLite) 2,136 / 2,136; AI 285; security 150; lifecycle 85; MySQL concurrency 11 / 11. Known exceptions are pre-existing and classified D: on MySQL 8 tests (P810-RC-01 / 02); in the data suite 1 time-window test (P810-RC-04). See `phase-8-10-verification.md` §14. |
| Browser verification passes where the environment permits | ☑ 13 smokes, 220 checks, all passing (two needed a re-run for login timing; the harness fixes are recorded in `phase-8-10-verification.md` §14) |
| Documentation reconciled | ☑ this document, the post-RMS backlog and the Phase 8.10 logs |
| Remaining issues have explicit dispositions | ☑ §4 |
| Production owner and infrastructure blockers separated | ☑ §6 |
| Working tree clean | see the final report |

## 8. Phase 8.11: production release and stabilisation (narrow scope)

Only after the owner actions in §6:
1. Production backup.
2. Deployment.
3. Migrations.
4. Worker and scheduler start.
5. Health checks.
6. Smoke tests.
7. Permission checks.
8. Core lifecycle check.
9. Queue checks.
10. Monitoring.
11. Rollback by restore if a genuine release-blocking defect appears.

No features. Production defects only. Then freeze the production baseline and declare **RMS COMPLETE**.
