# Phase 8.6 Security Review: Data Governance Implementation

**Scope:** the Phase 8.6 implementation on `feature/sep_25_hrm` (from `dcff76e`). It covers the discovery findings SEC-1 … SEC-8 (`phase-8-6-discovery.md` §17), plus issues found while implementing.

## 1. Discovery findings: status

| ID | Finding | Status after 8.6 | Evidence |
|---|---|---|---|
| SEC-1 (Critical) | Filament missing-policy-method fallback allowed deletes | **Closed on this branch**, and stronger than the containment (strict mode in local and test; fail-closed gate in production; policy coverage test). **Production still pending** (hotfix, D8.6-030). | `StrictAuthorizationTest`, `PolicyCoverageTest`, `DeleteAuthorizationTest` |
| SEC-2 | Template re-apply with only `requisitions.update`; unaudited remap | **Closed:** `pipeline.configure` + open requisition + reason; remap recorded and audited | `PipelineTemplateGovernanceTest` |
| SEC-3 | Raw settings CRUD bypassed validation | **Closed:** read-only resource; typed editor + service with reason and cross-field rules | `SettingsHistoryTest` |
| SEC-4 | No separation of duties in automation | **Closed** (D8.6-020; CHRO and VP HR exempt by decision) | `AutomationAndTemplateGovernanceTest`, `AutomationRuleServiceTest` |
| SEC-5 | Master-data force delete with irreversible cascades | **Closed** at the policy (`false`) and the model (throws). Foreign-key actions unchanged (P86-BACKLOG-001). | `MasterDataLifecycleTest` |
| SEC-6 | Offer template delete bypassed the locked offer term | **Closed:** a referenced or versioned template cannot be deleted | `OfferLetterIntegrityTest` |
| SEC-7 | Audit log mutable, global, explicit payloads unredacted | **Partly closed:** payloads redacted, hidden values never logged. Immutability, retention and hierarchy scoping stay with 8.8 (P86-BACKLOG-004). | `AuditFoundationTest` |
| SEC-8 | Calendar token rotation invisible in audit | **Closed:** hidden-attribute changes are recorded as `[changed]` without values | `AuditFoundationTest` |

## 2. Findings during implementation

### SEC-86-I-01: Manual joining creation open to any panel user (MEDIUM, fixed here; present on `main`)

| | |
|---|---|
| Component | `CandidateJoiningPolicy` had no `create()`. Filament's missing-method fallback allowed `ListCandidateJoinings` CreateAction and `/admin/candidate-joinings/create`. |
| Exploit / impact | Any authenticated panel user (the employee role holds no `joining.confirm`) could create a Pending joining record, without an accepted offer, for an application in their hierarchy (the picker is scoped). Marking it Joined still needs `joining.confirm` and the lifecycle service. The risk is data integrity (joinings outside the 8.3 offer lifecycle), not direct pay. |
| Affected | Every environment built from `main`, including production; the hotfix branch does not cover it. |
| Fix (this branch) | `create()` requires `joining.confirm`. Strict mode and the fail-closed gate prevent the class of bug. |
| Test | `StrictAuthorizationTest` (employee: 403; recruiter: allowed). Mutation-checked. |
| Required action | Add to the hotfix before production deployment (see implementation §7). |
| Blocks 8.6 | No (fixed on the branch); a production action is pending. |

### SEC-86-I-02: A refused automation save could hide a change on retry (LOW, fixed)

`AutomationRuleService::update()` compared the new configuration with the in-memory model. After a refused save (for example, missing reason), the model already held the change, so a retry looked unchanged: saved without a version or a reason.

**Fix:** it now compares with the stored configuration. Covered by `AutomationAndTemplateGovernanceTest`.

### SEC-86-I-03: Read-only history models without policies (INFORMATIONAL, fixed)

Twelve models listed in relation managers had no policy, which Filament treated as allowed:
- stage and offer status history;
- requisition and incentive approvals, and incentive adjustments;
- offer revisions;
- automation versions;
- job distributions;
- duplicate matches;
- pool memberships;
- slot bookings;
- requisition pipeline snapshots.

They are now explicit read-only policies (`ReadOnlyRecord`: view allowed within the owner page; create, update and delete denied). Their relation managers use only custom actions with their own authorization, so behaviour is unchanged.

## 3. Controls added

- **Master data:** `settings.manage` plus a reason on deactivate, archive and restore; no force delete; no bulk destructive actions; in-use check; archived records never offered for new use (server-side guard plus pickers).
- **Configuration authority unchanged:** no new permissions; existing ones are enforced in services, not only the UI.
- **Separation of duties** for automation activation.
- **Reasons** on configuration changes (settings, automation lifecycle, pipeline re-apply, master data) stored in `audit_logs.reason` and version summaries.
- **Issued offer letters:** stored privately on the `local` disk, served only through the offer-download action (`OfferPolicy::view`). Integrity is verified by SHA-256; a mismatch is never served as the issued letter and is reported.
- **Governance audit:** read-only (test asserts no write statements); reports drift by group name only, never values.
- **Configuration fingerprint:** reads named, non-secret keys only; hashes values; does not read `.env` secrets.

## 4. Privacy

- **Audit payloads:** no compensation in new audit payloads. Offer compensation stays redacted, now also in explicit records.
- **Letter audit row:** `offer_letter_issued` records ids, source, version and hash, not content.
- **Incentive pricing snapshots:** contain rule and slab parameters and the basis; no candidate data.
- **Frozen snapshot names:** department, designation, location and source only; no people's names (8.2 rule kept).
- **Setting history:** holds configuration values and reasons only.

## 5. Residual risks

| Risk | Severity | Owner |
|---|---|---|
| SEC-1 and SEC-86-I-01 undeployed on production (`main`) | High until deployed | Release: D8.6-030 / P86-BACKLOG-007 |
| Database cascades still possible through raw SQL | Low | P86-BACKLOG-001 |
| Audit log not immutable or retention-bound | Medium | 8.8 (P86-BACKLOG-004) |
| Phase 8.7 findings (queue payload privacy, send-time consent, etc.) | per 8.7 security review | Phase 8.7 |

**No known critical or high finding remains open on this branch.** The production deployment of the security hotfix is an explicit pending release action, not an accepted risk.
