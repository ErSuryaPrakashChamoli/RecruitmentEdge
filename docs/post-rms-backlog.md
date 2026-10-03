# Post-RMS Backlog

**For:** the product owner and engineering, after the RMS release.

**What belongs here:** work that is useful but **not required for a safe production release** of the RMS. It was classified during the Phase 8.10 final release readiness review (`rms-final-release-candidate.md`).
- Nothing here is scheduled.
- None of it blocks the release candidate or Phase 8.11.
- New work goes here, or into an RMS 2.0 backlog, unless separately authorised.

**Older items:** every item still **Open** in `docs/backlog.md` (Phases 7–8.9) is also post-RMS. The only exceptions are items marked **production action**; those are listed as owner actions in `rms-final-release-candidate.md` §6.

## 1. Decision-gated product work (owner decision first)

| Item | Findings | Decision |
|---|---|---|
| Interview time-zone model: one coherent zone for entry, display, messages, portal and calendar sync | P810-PM-01, OP-18, DI-06 | D8.10-006 (Product). Until it is decided, see the operational control in the release candidate §6. |
| Hiring-manager and interviewer persona outside the recruitment hierarchy | PM-02 | D8.10-007 |
| Offer governance: maker-checker, salary bands | PM-06 | D8.10-008 |
| Milestone ownership (offer, joining and post-join stages only through services) and closing after Joined | DI-03, DI-05, AI-05 | D8.10-010 |
| AI approval friction: typed confirmation, second approver | rest of AI-01 | D8.10-012 |
| Intelligence scores in automation | AI-04 | D8.10-013 |
| Incentive event dates; payment reconciliation; retention checks at maturity | DI-07, DI-08; D8.10-009(a)(d)(e) | D8.10-009 |
| Unique index for one incentive per rule and application | DI-01 (DB layer) | D8.10-022 (duplicate report first) |
| Requisition governance: field audit, locking after approval, delete with dependants | DI-09, PM-08 | D8.10-017 |
| Cost-per-hire population | DI-10 | D8.10-018 |
| Candidate offer self-service and e-signature | PM-04 | D8.10-016 |
| API, outbound events, SSO, SCIM | PM-05 | D8.10-015 |
| Tenancy | §8 of the discovery | D8.10-001 |
| Localisation, accessibility targets | PM-18, PM-19 | D8.10-019 |

## 2. Security hardening, below the release bar

| Item | Note |
|---|---|
| P810-SEC-003 | Candidate password rule: align with staff, or accept (D8.8-002 / 003) |
| P810-SEC-005 | Compensation section of the offer form. Every seeded `offers.manage` role already holds `compensation.view`, so only custom roles are exposed. |
| P810-SEC-007 | Throttle `/up`, or restrict it to monitoring |
| P810-SEC-009 | Remaining employee pickers: follow-up, manual activity, talent-pool owner, requisition form |
| P810-SEC-010 | Holder-only rule for removing the CHRO role in revoke and separation (the last-CHRO lock already holds) |
| P810-SEC-011 | Audit candidate-document changes (E-06 residual) |
| P810-SEC-012 | Product rule: may an interviewer's Rejected result close the application? |
| P810-SEC-013 | MFA on the calendar OAuth routes |
| P810-SEC-014 | Webhook timestamp tolerance |
| P810-SEC-016 | Rediscovery results visible beyond the runner's reach (names only; acting on them is refused) |
| `/storage` headers | `nosniff` and a restrictive CSP on the public disk. Uploads are already raster-only (P810-SEC-002). |
| Carried items | SEC-88-02, 04 remainder, 06 scanning, 16, 18, 20, 21, 22, 23, 25, 26, 27, 28; P86-BACKLOG-004; P88-BACKLOG-002; P89-SEC-006 / 008 / 010 / 012 residuals |

## 3. AI maturity

| Item | Note |
|---|---|
| P810-AI-02 | Tag and length-cap untrusted fields; adversarial `ai:evaluate` cases. Zero-click exfiltration is already closed (AI-03), and every write already needs an informed approval (AI-01). |
| P810-AI-03 remainder | Links in AI output (following one needs a click); a panel CSP |
| P810-AI-06 | Per-turn call caps, deadline, input limit; queued or streamed turns |
| P810-AI-07, AI-08 | Provider retries and circuit breaker; knowledge-base indexing retries |
| P810-AI-09 | Model and prompt provenance on proposals; `request_id` on action logs (additive columns) |
| P810-AI-10 | Retrieved excerpts outside the system role |
| P810-AI-13, AI-14 | Risk-level docblock; screening of Hiring Memory summaries |
| Deeper predictive intelligence, prompt registry, disagreement feedback, grounding checks | discovery §7.4 |

## 4. Integrity and lifecycle polish

| Item | Note |
|---|---|
| DI-06 | Enter the actual joining date (bounded, business time zone); depends on D8.10-006 |
| DI-11 | A same-stage transition is a no-op |
| DI-12 | State-machine dead ends: re-offer after Withdrawn or Expired; reactivation after a closed joining |
| DI-13 | Lock the application when scheduling an interview |
| DI-15 | Audit application field edits (needs a `.ai/rules` review) |
| DI-16 | Validate offer terms |
| DI-14, DI-17 | Side effects after commit (before any Redis move); foreign-key hardening (D8.6-006) |
| Legacy joinings | Joinings created by hand before P810-DI-04 without an accepted offer cannot be marked Joined. A **controlled remediation procedure** is to be decided later (report first, no automatic repair). |
| `created_by` on applications | P810-SEC-004 residual: application creation has no actor column (needs a migration) |

## 5. Operations and platform (engineering side)

| Item | Note |
|---|---|
| P810-OP-04 | Per-process worker heartbeat |
| P810-OP-10 | Stateless webhook and health routes |
| P810-OP-11 | Object storage (D8.9-017) |
| P810-OP-12 | Command-palette exact routing |
| P810-OP-15, OP-16, OP-19 | Health-check and grace-period details; slow-query and deprecation logging; scheduled `storage:audit` |
| P810-OP-20 | `tries` / `backoff` on queued security mails. A candidate can re-request a lost link or code today. |
| CI (H3) | MySQL job for the main suite, plus `tests/Concurrency`. First make the 7 MySQL-sensitive assertions order-independent (P810-RC-02) and the concurrency fixture codes deterministic (P810-RC-03). |
| P810-RC-01 | Automation rule change detection is key-order sensitive on MySQL: a rename asks for a reason and writes a version |
| P810-RC-04 | Outcome dashboard default end date is the UTC date, while periods are IST: 00:00–05:30 IST hides that night's new outcomes from the default view |
| H1, H2 | Browser smokes in the repository; static analysis (dependency approval) |
| H5 | Dead code (P87-BACKLOG-007), Pint finding, `PruneExpiredCache` |
| Performance | Benchmarks B1–B5 (D8.9-011); materialisation (D8.9-016); a search engine (D8.9-015); Redis (D8.9-014); partitioning and archival (D8.9-024) |

## 6. Product breadth

PM-07 (job boards), PM-09 (screening and assessments), PM-10 (interview model), PM-12 (selection record), PM-14 (lifecycle recovery tools), PM-15 (inbound communications), PM-16 (late-funnel automation), PM-17 (digests and scheduled reports), PM-20 (post-hire quality), PM-21 (sourcing breadth), PM-26 (polish).

Also: advanced analytics and dashboards, SSO expansion, passkeys, marketplace, globalisation.

## 7. Documentation

DOC-01 (`.ai/rules` accuracy), DOC-02, DOC-04, DOC-05, DOC-06 (README, `composer.json` name), DOC-07 remainder; the proposed backlog edits in `phase-8-10-backlog-reconciliation.md` §5.
