# Organization & Hierarchy Configurability — Audit

**For:** the project owner, product owner and release owner. Two decisions depend on it:
- whether Recruitment Edge can be sold to organisations whose HR/recruitment structure is not CHRO → VP HR → Manager → Assistant Manager → Recruiter;
- whether Client #1's existing single-tenant RMS can become SaaS Tenant #1.

**Date:** 2026-10-07.

**Code inspected:**
- `feature/production-readiness` at `09bd611`. Since the tested candidate `cf9082c`, its application code differs only in `app/Providers/Filament/AdminPanelProvider.php` (+4/−1, unrelated to this audit).
- The single-tenant line `9cba8e3` (`origin/main` and `origin/production`, 75 migrations) and the Phase 8.11 line `226bc7d` (164 migrations), both read with `git show`.

**What this does not do:**
- It changes no code, migration, test or configuration.
- It runs no migration, test or seeder.
- It queries no database.
- No production or client system was seen. Client #1's deployed code and data are **UNKNOWN** (`production-bring-up-stage-2a-release-evidence.md` §2).

**Labels:**
- **VERIFIED**: read in the code or git in this audit.
- **RECORDED**: stated in a repository document (cited).
- **INFERENCE**: reasoned from verified code, never executed.
- **UNKNOWN**: not determinable from the repository.

**Dependency classes (§2, §4, §6):**
- **A** harmless display or default;
- **B** permission or security;
- **C** workflow;
- **D** hierarchy;
- **E** automation;
- **F** reporting or analytics;
- **G** database or schema;
- **H** hard blocker for custom organisations.

**Result in one line:**
- The reporting hierarchy and all data visibility are data-driven, at any depth.
- Roles and permissions are editable. But some identity and automation rules recognise only the six seeded role keys, and one automation path recognises only their seeded **names**.
- Requisition ownership is five fixed slots named after the seeded levels.
- Migrating Client #1 from `9cba8e3` is **not ready**: a migration is likely to abort part-way (§8.1), and no upgrade has been rehearsed on real data.

---

## 0. Verdicts

| Area | Rating | One-line reason |
|---|---|---|
| A. Reporting hierarchy | **FULLY CONFIGURABLE** | `employees.reports_to_id` plus a closure table: any depth, multiple roots, editable in the UI, tenant-scoped, audited. Caveats in §1.4 |
| B. Roles | **PARTIALLY CONFIGURABLE** | Create, rename and set permissions in the UI. UI-created roles never get a key, so key-based rules ignore them. The tenant owner must hold CHRO |
| C. Workflow responsibility | **PARTIALLY CONFIGURABLE** | Application ownership works for any employee. Requisition ownership is five fixed, level-named slots with fixed labels, and alerts and automation follow the "Manager" slot |
| D. Approvals | **PARTIALLY CONFIGURABLE** | Every approver is a permission plus reporting-line scope, so it works for any org. All approvals are single-step, with no configurable chain. The automation self-activation exemption is hard-coded to `chro`/`vp_hr` |
| E. Automation | **PARTIALLY CONFIGURABLE** | Relative targets (recruiter, direct manager, two levels up) work for any org. Role targets are a closed list of four seeded roles, matched by editable **name**. Escalation can reach at most two levels above the recruiter by count |
| F. Reporting | **FULLY CONFIGURABLE** (display caveats) | No dashboard, KPI, target, leaderboard or incentive depends on a role key or a level. Scope is the reporting tree plus permissions. Labels and two drill-downs assume the seeded model |
| G. AI | **PARTIALLY CONFIGURABLE** | No prompt states an org model. Scope and tools use permissions plus hierarchy. The requisition data contract sends `manager_ref`/`vp_hr_ref`, and one tool applies a narrower visibility rule |
| H. Migration from old RMS | **NOT READY** from `9cba8e3`; **REQUIRES REHEARSAL** from `226bc7d` | From `9cba8e3` the Phase 4 grant migration is expected to fail (§8.1). No path has been rehearsed on production-like data |

**Critical question 1 (§11): PARTIALLY. Critical question 2 (§12): PARTIALLY. Overall: PARTIALLY CONFIGURABLE.**

---

## 1. Hierarchy model

### 1.1 Implementation

- **Source of truth:** `employees.reports_to_id`, a nullable self-referencing FK with `nullOnDelete` (`2026_08_26_100229_create_employees_table.php:24`). VERIFIED.
- **Closure table:** `employee_hierarchy (tenant_id, ancestor_id, descendant_id, depth)`, primary key `(ancestor_id, descendant_id)` (`2026_08_26_100231_create_employee_hierarchy_table.php:15-20`). VERIFIED.
  - `EmployeeObserver` maintains it: on `created` it inserts the depth-0 self row plus one row per ancestor; on `updated` it detaches the moved subtree and re-attaches it (`app/Observers/EmployeeObserver.php:45-126`). VERIFIED.
- **Every read goes through `HierarchyService`, never by walking `reports_to_id`** (`app/Services/HierarchyService.php:32-176`). VERIFIED:
  - `visibleEmployeeIdsFor` — the visibility set;
  - `canView` — single-record check;
  - `descendantIdsOf` / `ancestorIdsOf` — subtree and chain;
  - `managementChainOf` — managers ordered by depth;
  - `treeFor` — the org chart.
- **Single writer:** `HierarchyIntegrityService::place()` is the only writer of `reports_to_id` on update. The model guards the column (`Employee.php:55-58`; `GuardsLifecycleAttributes.php:17-27`). VERIFIED.

### 1.2 Properties

| Property | Answer | How | Evidence | Label |
|---|---|---|---|---|
| Reporting relationships are data-driven | **YES** | `reports_to_id` plus the closure table | above | VERIFIED |
| Arbitrary depth | **YES** | No depth limit. `depth` is an unsigned int. `treeFor` and the Blade view recurse without a cap. The only "level" limits are automation targets (§4). `employees.level` and `employees.category` are free text that no logic reads | `HierarchyService.php:156-174`; `EmployeeForm.php:76-79`; `ConditionEvaluator.php:21` (automation condition nesting, not the org) | VERIFIED. Tests use 4–5-level chains only |
| Employee can report to any valid employee | **YES, with guardrails** | Not restricted by role, designation or level. The manager must be an Active, non-deleted employee in the same tenant, and inside the actor's visible tree unless the actor holds `hierarchy.view-all`. Nobody moves themselves. A holder of a protected role (CHRO) can be moved only by another holder | `HierarchyIntegrityService.php:35-49, 102-123, 158-166` | VERIFIED |
| Multiple roots | **YES** | `reports_to_id` is nullable. The org chart draws one tree per root for `hierarchy.view-all` holders. Creating a root needs view-all | `OrganizationHierarchy.php:55-63`; `HierarchyIntegrityService.php:106-109` | VERIFIED |
| Hierarchy changes supported | **YES** | Employee create (`CreateEmployee.php:23-37`), Employee edit (`EditEmployee.php:51-60`), Organization / Hierarchy → Reassign Manager (`OrganizationHierarchy.php:94-139`), candidate-to-employee conversion and rehire (`EmployeeConversionService.php:69, 95-112`). Separation never changes `reports_to_id`. A handoff and an employee delete are blocked while direct reports remain. No import, API or AI path writes employees | as cited | VERIFIED |
| Cycles prevented | **YES** for one change at a time; **not proven** under concurrency | `place()` refuses a manager inside the employee's own subtree (`HierarchyIntegrityService.php:82-84`). The observer re-checks (`EmployeeObserver.php:31-43`). The UI hides the employee's own subtree from the options. See §1.4 for concurrency | as cited | VERIFIED / INFERENCE |
| Visibility follows reporting relationships | **YES** | Visible = self plus closure descendants. Org-wide = the **permission** `hierarchy.view-all`, not a role. Policies combine a permission with `canView` (e.g. `CandidateApplicationPolicy.php:23-52`). About 60 files use this service | `HierarchyService.php:32-60` | VERIFIED |
| Tenant-scoped | **YES** | `Employee` uses `BelongsToTenant`. Every closure read and write names the tenant. Composite FKs `(tenant_id, ancestor_id)` and `(tenant_id, descendant_id)` | `HierarchyService.php:113-116`; `2026_10_04_025116_enforce_tenant_ownership.php:150-151` | VERIFIED |
| Changes audited | **YES** (successful changes) | `AuditLog::record($employee, 'reporting_line_changed', old, new + actor)` plus the `Auditable` diff on the employee | `HierarchyIntegrityService.php:88-89`; `Auditable.php:34-62` | VERIFIED |
| Concurrency-safe | **PARTIAL** | `place()` locks both rows in id order inside a transaction. The cycle check itself is a non-locking read. There is no race test | `HierarchyIntegrityService.php:69-84`; `tests/Concurrency/*` (none for the hierarchy) | VERIFIED / INFERENCE |

### 1.3 Who can change the hierarchy (seeded defaults)

- **Permissions required:**
  - The org chart action needs `hierarchy.reassign`.
  - The Employee form needs `users.manage`.
- **Seeded holders:**
  - Both permissions are held by `chro` (through `*`).
  - `vp_hr` holds `hierarchy.reassign` only.
  - `hierarchy.view-all` is seeded to `chro` only.
  - Evidence: `RolePermissionSeeder.php:21-22, 106-114`. VERIFIED.
- **Editable:** a tenant can grant any of these to any role through the Roles UI. A grantor can only grant permissions they hold (`RoleAssignmentService.php:285-292`). VERIFIED.

### 1.4 Hierarchy gaps found

1. **Concurrency.** INFERENCE, untested.
   - (a) The Employee edit form wraps `reassign()` in an outer transaction (`EditEmployee.php:55`). Plain reads happen before the row lock, so on MySQL REPEATABLE READ the cycle check can see a stale snapshot.
   - (b) Two moves that lock *different* row pairs can together form a loop: X1 under Y2 and Y1 under X2, where X2 is below X1 and Y2 is below Y1.
   - (c) Creating an employee takes no lock on the manager. A concurrent move of the manager's subtree can leave the new employee's deeper closure rows stale.
   - `tests/Concurrency/` has no hierarchy race test. VERIFIED.
2. **The "Reports To" picker on the Employee form stops at 500 people**, ordered by first name (`EmployeeForm.php:61`). The org-chart reassign has no limit. VERIFIED.
3. **Strict tree.** Each employee has one manager. There is no dotted-line or matrix reporting. VERIFIED.
4. **Drift detection is shallow.** `identity:audit` compares depth-1 links only (`closure_out_of_sync`, `stale_closure_link`; `IdentityAuditor.php:112-129`).
   - It does not detect missing self rows or missing or stale rows at depth ≥ 2. VERIFIED.
   - **No command exists to rebuild the closure table.** VERIFIED.
5. **Refused moves are not in `audit_logs`.** Out-of-scope, cycle and protected-role refusals go only to the application log as `identity.hierarchy_rejected` (`HierarchyIntegrityService.php:169-174`). VERIFIED.
6. **The org chart shows a fixed header:** `"CHRO → VP HR → Manager → Assistant Manager → Recruiter"` (`resources/views/filament/pages/organization-hierarchy.blade.php:2`). Display only (A). VERIFIED.
7. **Org-chart drill-downs are for one person only.** "Candidates" filters on that exact `recruiter_id`, and "Vacancies" on `manager_id` only (`OrganizationHierarchy.php:81, 86`). The node label "N reports" counts every descendant, not only direct reports. Display (A/F). VERIFIED.

---

## 2. Roles

### 2.1 Role mechanics

| Question | Answer | Evidence | Label |
|---|---|---|---|
| Can tenants create custom roles? | **YES**, with `roles.manage` (seeded to `chro` only). Fields are name (unique per tenant) and permissions. A creator can grant only permissions they hold | `ListRoles.php`; `CreateRole.php:23-34`; `RoleForm.php:23-41`; `RoleAssignmentService.php:146-150, 285-292` | VERIFIED |
| Can roles be renamed? | **YES**, including protected ones. Nobody can edit a role they hold. The rename is audited | `RoleAssignmentService.php:165-214` | VERIFIED |
| Are role keys immutable? | **YES once set** (`Role::updating` throws). **UI-created roles never get a key:** `createRole` stores name and guard only, and the key field is disabled and never saved. Only `RolePermissionSeeder` and `add_key_to_roles_table` set keys | `Role.php:46-49`; `RoleAssignmentService.php:149`; `RoleForm.php:27-32`; `IdentityAuditor.php:108` | VERIFIED |
| Key uniqueness | Per tenant: `(tenant_id, key)` and `(tenant_id, name, guard_name)` | `enforce_tenant_ownership.php:524-531` | VERIFIED |
| Protected roles | `chro` only (`config/identity.php:22` → `roles.is_protected`). Protected means: cannot be deleted or unprotected; permissions not editable; granted or removed only by a holder; last-CHRO protection | `Role.php:51-60`; `RoleAssignmentService.php:66-87, 182-184`; `AuthorityGuard.php:93-108, 178-202` | VERIFIED |
| Are permissions role-based? | **YES** (spatie, teams = tenant). No application path writes direct user permissions. An architecture test confines assignment to `RoleAssignmentService` / `StaffAccessService`. No role-based `Gate::before` super-admin exists | `IdentityArchitectureTest.php:25-35`; `AppServiceProvider.php:161-167` | VERIFIED |
| Are hierarchy and roles independent? | **Mostly YES.** Placement never checks the manager's role. A "recruiter" is any employee who owns an application. Visibility is the tree. **Exceptions:** automation `role:*` targets (§4), the self-activation exemption (§5), MFA by role key, and the tenant owner needing CHRO | `HierarchyIntegrityService.php:102-122`; `PerformanceEngine.php:173-181` | VERIFIED |
| Roles for a new tenant | `TenantDefaults::apply()` runs `RolePermissionSeeder`, which creates exactly `chro, vp_hr, manager, assistant_manager, recruiter, employee`. The **display name equals the raw key** (e.g. `vp_hr`). The owner is invited with CHRO | `TenantDefaults.php:17-21`; `RolePermissionSeeder.php:321-339`; `TenantInvitationService.php:115-120` | VERIFIED |
| Roles for Tenant #1 on upgrade | The seeder does **not** run. Existing roles are kept, keyed by exact seeded name, and given additive grants (§8) | `add_key_to_roles_table.php:16-31`; `OpsMigrate.php:38` | VERIFIED |
| Base role `employee` | Granted automatically on conversion, rehire and restore. Holds `referrals.submit` only. **Not protected, so it can be deleted.** Once deleted, conversion, rehire-without-login and restore-from-revoked throw "The employee role does not exist." | `config/identity.php:26`; `RolePolicy.php:41`; `TenantInvitationService.php:105`; `RoleAssignmentService.php:108`; `StaffAccessService.php:262` | VERIFIED code; failure INFERENCE, untested |

### 2.2 Codebase-wide sweep: every actual dependency on the five titles

**Scope:** `app/`, `config/`, `database/`, `resources/`, `routes/`, `bootstrap/`. Comments, docs, tests and `vendor/` are excluded.

**Search terms:** `chro`, `vp_hr` / "VP HR", `manager`, `assistant_manager` / "Assistant Manager", `recruiter`, in any spelling.

**Findings:**
- `routes/` and `bootstrap/` contain **no** role middleware.
- **No code requires** the employee in `candidate_applications.recruiter_id` to hold the `recruiter` role. Nor does any code require the person in `manager_id`, `assistant_manager_id` or `vp_hr_id` to hold the matching role. These are owner and stakeholder slots, not role checks. VERIFIED.

**K1 — role key or role name used in logic**

| # | file:line | Dependency | Class |
|---|---|---|---|
| K1-01 | `app/Services/Automation/RecipientResolver.php:31-34, 42, 64-65` | `role:assistant_manager`, `role:manager`, `role:vp_hr`, `role:chro`, resolved by `$manager->user->hasRole(substr($target, 5))`. spatie 8.3.0 `hasRole(string)` compares the role **name** (`vendor/spatie/laravel-permission/src/Traits/HasRoles.php:372-376`). Only ancestors of the recruiter are searched | **E + D + H** |
| K1-02 | `RecipientResolver.php:42`; `AutomationRuleValidator.php:255`; `Actions/Handlers/EscalateAction.php:36` | Escalation targets are a closed whitelist of 7. Custom roles and levels 3+ are rejected | **E + H** |
| K1-03 | `app/Services/Automation/AutomationTemplateCatalog.php:109` | The shipped "Offer pending" template escalates to `role:vp_hr` | **E + H** |
| K1-04 | `app/Services/Automation/AutomationRuleService.php:34, 388` | `SEPARATION_OF_DUTIES_EXEMPT_ROLES = ['chro', 'vp_hr']`, matched by **key**. Keyless custom roles can never be exempt | **B + C + E** |
| K1-05 | `config/identity.php:53`; `MfaService.php:55-57, 70-72` | MFA required for role keys `chro`, `vp_hr`, `manager`. Custom roles get MFA only through `privileged_permissions` | **B** |
| K1-06 | `config/identity.php:22, 29`; `AuthorityGuard.php:166-202`; `EmploymentGate.php:60-72`; `EmployeeLifecycleService.php:60, 146, 197, 474`; `RoleAssignmentService.php:85` | CHRO key = protected role, last-CHRO protection, sole-CHRO exemption | **B + D** |
| K1-07 | `TenantInvitationService.php:117, 541`; `TenantOwnershipService.php:72-73`; `TenantsProvision.php:20`; `Platform/Pages/TenantDetail.php:143` | The tenant owner must hold the role keyed `chro`. The provisioning invitation must be exactly `[chro]` | **B** |
| K1-08 | `config/identity.php:26`; `TenantInvitationService.php:105, 549`; `RoleAssignmentService.php:108`; `StaffAccessService.php:262` | Base role by key `employee`. It can be deleted (`RolePolicy.php:41`) | **B + C + H** (latent) |
| K1-09 | `database/seeders/RolePermissionSeeder.php:105-339` via `TenantDefaults.php:19` | Every tenant is provisioned with the six-role bundle and the permission matrix keyed by these role names | **B + G** |
| K1-10 | `2026_09_26_204338_add_key_to_roles_table.php:16-31` | Keys only roles whose name exactly equals a seeded key | **G** |
| K1-11 | Grant migrations for phases 4, 5, 6, 7, 8.1, 8.2, 8.2-review and 8.3 (`where('name', …)`); 8.4 and 8.5 (`Role::byKey`); billing (`where('key','chro')`) | Additive grants to roles found by seeded name or key. Renamed or custom roles are skipped | **G** |
| K1-12 | `RoleAssignmentService.php:149`; `RoleForm.php:27-32` | UI-created roles are keyless, which excludes them from K1-04 to K1-08 | **B + H** |
| K1-13 | `database/seeders/AdminUserSeeder.php:58`; `OrganizationSeeder.php:29-35` | `assignRole('chro')`, CHRO designations. Development seeders only | A |

**K2 — schema and relationships named after a level**

| # | file:line | Dependency | Class |
|---|---|---|---|
| K2-01 | `2026_08_26_103807_create_recruitment_requisitions_table.php:29-33`; `RecruitmentRequisition.php:44-48, 144-176` | Five nullable FKs to `employees`: `reporting_manager_id`, `hiring_manager_id`, `assistant_manager_id`, `manager_id`, `vp_hr_id` | **G** |
| K2-02 | `RecruitmentRequisition.php:271-289` (`scopeVisibleTo`), `:363-379` (`involvedEmployeeIds`); `EmployeeReferral.php:141-151` | Requisition visibility uses all five plus `created_by` plus recruiters (about 25 consumers, including the policy's view, update and approve) | **B + D** |
| K2-03 | `SearchRequisitionsTool.php:66-72`; `AutomationScopeResolver.php:108-110`; `DryRunAutomationRule.php:113`; `OwnershipHandoffService.php:101-104` | Four **different** subsets of the five slots. These disagree with `scopeVisibleTo` | **B + D + E** |
| K2-04 | `RecipientResolver.php:61, 88`; `DispatchRecruitmentAlerts.php:107`; `NotifyReviewersOfReferral.php:28-29`; `HiringRiskRadar.php:284`; `NextBestActionService.php:177` | Behaviour that reads **`manager_id` only**: the `requisition_manager` target (then `hiring_manager_id`), the escalation chain base for requisition events, the vacancy-ageing alert (then `created_by`), referral alerts, risk owner, next-best-action owner | **E + C** |
| K2-05 | `CareerApplicationService.php:100`; `EmployeeConversionService.php:69` | Online applicant owner = first recruiter ?? `manager_id` ?? `created_by`. A converted hire's default manager = reporting ?? hiring ?? `manager_id` | **C** |
| K2-06 | `RecruitmentRequisitionForm.php:83-122`; `RecruitmentRequisitionsTable.php:77-79`; `OrganizationHierarchy.php:86` | Fixed labels "Reporting Manager", "Hiring Manager", "Assistant Manager", "Manager", "VP HR". Options are every tenant employee, with no filter or validation. The only people filter is `manager` | **A + G + F** |
| K2-07 | `AiProjector.php:213-215`; `GetRequisitionTool.php:51` | AI data contract `manager_ref`, `vp_hr_ref` | **A + G** |
| K2-08 | `candidate_applications.recruiter_id` (NOT NULL) and `recruiter_id` on follow-ups and activities; the `recruitment_requisition_recruiters` pivot; about 99 files | The domain "owner of the work". Any employee can hold it, and every recruitment policy and metric scopes through it | **G + D + F** (not role-bound) |

**K3 — user-facing text** (all **A**). VERIFIED:
- the org chart header;
- `AppTheme.php:86` ("CHRO, VP HR, Management…");
- `RecipientResolver.php:26-34` labels ("VP HR (in hierarchy)" etc.), also shown in the escalation "Level" column;
- template descriptions (`AutomationTemplateCatalog.php:57-197`);
- requisition slot labels;
- CHRO-named errors (`AuthorityGuard.php:106`, `TenantOwnershipService.php:73`, `EnforceSeparations.php:29`, `ReconcileAccess.php:60`);
- about 30 "Recruiter" labels across tables, exports, dashboards and portal text;
- enum cases `Recruiter = 'recruiter'` (`IncentiveBeneficiary`, `AutomationScope`, `TimelineSource`, `SchedulingChannel`), whose values are persisted;
- seeded role display names equal to raw keys (`vp_hr`), shown in Users, Access Review, Profile, Invite and the AI prompt.

**Count by class** (64 rows in the full sweep; a row with several letters counts once per letter):

| Class | A | B | C | D | E | F | G | H |
|---|---|---|---|---|---|---|---|---|
| Rows | 22 | 17 | 10 | 12 | 13 | 7 | 14 | 6 |

The six H rows are K1-01, K1-02, K1-03, K1-04 (for small custom orgs), K1-08 (latent) and K1-12. All of them are in automation or identity. None is in the hierarchy or in data visibility.

---

## 3. Recruitment requisitions

### 3.1 Why the five fields exist

- **Origin.** All five were in the first commit (`989acad`, "first push") and have not changed except for tenancy FK hardening (`403102e`; `enforce_tenant_ownership.php:378-386`). VERIFIED.
- **No design document gives a reason.** `docs/` never mentions the columns. VERIFIED.
- **The only stated purpose is visibility.** `.ai/rules/policies-policies.md:9`: a requisition's hierarchy visibility "depends on several columns at once (reporting_manager_id, hiring_manager_id, assistant_manager_id, manager_id, vp_hr_id, created_by, plus the recruiters pivot)". RECORDED.
- **The model's docblocks name "the named management chain on the requisition"** (`RecruitmentRequisition.php:264-268, 357-362`). VERIFIED.
- **Conclusion (INFERENCE):**
  - They are named stakeholder slots, copied from the seeded role ladder, that grant visibility.
  - **Approval never reads them.**
  - Alerts and automation read mostly `manager_id`.

### 3.2 Schema and form

| Field | DB | Form label | Required | Choices filtered |
|---|---|---|---|---|
| `reporting_manager_id` | nullable FK | "Reporting Manager" | No | No |
| `hiring_manager_id` | nullable FK | "Hiring Manager" | No | No |
| `assistant_manager_id` | nullable FK | "Assistant Manager" | No | No |
| `manager_id` | nullable FK | "Manager" | No | No |
| `vp_hr_id` | nullable FK | "VP HR" | No | No |
| recruiters (pivot) | — | "Assigned Recruiters" | No | No |

- **Labels:** string literals (`RecruitmentRequisitionForm.php:87-117`). There is no `lang/` directory, no `__()` call, and no setting for them. **They cannot be changed without code.** VERIFIED.
- **Choices:** every tenant employee (open finding P810-SEC-009, `phase-8-10-security-review.md:134-137`). RECORDED.

### 3.3 Effect of leaving slots empty

- **`vp_hr_id` / `assistant_manager_id` empty:** almost no effect.
  - A VP HR or Assistant Manager who is an ancestor of the manager, a recruiter or the creator still sees the requisition through the tree.
  - Only the AI's `vp_hr_ref` becomes null.
  - VERIFIED / INFERENCE.
- **`manager_id` empty:**
  - The vacancy-ageing alert goes to the creator, or is silently dropped if there is no creator (`NotificationDispatchService.php:46-50`).
  - `requisition_manager` falls back to the hiring manager, then nobody.
  - For requisition-level automation events, `reports_to`, `levels_up` and `role:*` have no starting point and resolve to nobody (`RecipientResolver.php:88`).
  - Referral alerts reach recruiters only.
  - With no recruiters assigned as well, there is no risk owner and no Action Center item.
  - The org-chart "Vacancies" link and the table filter show nothing.
  - VERIFIED.

### 3.4 Answer: CEO → HR Director → TA Director → Team Lead → Recruiter

**Can this company use the requisition workflow without pretending these people are VP HR / Manager / Assistant Manager? NO, not without losing part of the workflow.**

- **What works without pretending.** VERIFIED:
  - Approval is the permission `requisitions.approve` plus scope (`RequisitionApprovalService.php:177-183`). A tenant can grant it to an "HR Director" role.
  - Visibility follows the reporting tree. The HR Director sees the TA Director's and Team Lead's requisitions without being named on them.
  - No slot is required, and no slot is checked against a role.
- **Why the answer is still NO:**
  1. **The HR Director, TA Director and Team Lead can only be recorded in slots labelled "VP HR", "Manager" and "Assistant Manager".** The labels cannot be changed without code (§3.2). Leaving those slots empty and using only "Hiring Manager", "Reporting Manager" and "Assigned Recruiters" is possible. VERIFIED.
  2. **Doing that switches off the requisition behaviour that reads `manager_id` (§3.3).** That behaviour is:
     - the vacancy-ageing alert recipient;
     - the escalation chain for requisition events;
     - referral review alerts;
     - the risk and next-best-action owner;
     - the org-chart vacancies link and the table filter.

     To keep it, the TA Director or Team Lead must be stored in the slot labelled "Manager". VERIFIED.
  3. **Automation can address the HR Director by role only as `role:vp_hr` ("VP HR (in hierarchy)").** That works only if the HR Director holds a role literally *named* `vp_hr` (§4). VERIFIED.
  4. **The AI sees the requisition's owner fields only as `manager_ref` and `vp_hr_ref`** (§7). VERIFIED.

---

## 4. Automation

### 4.1 What a customer can configure in the UI

| Capability | Answer | Evidence | Label |
|---|---|---|---|
| Direct manager (`reports_to`) as recipient, action owner or escalation target | **YES**, any org. Skips an unreachable manager and takes the next reachable ancestor | `RecipientResolver.php:62, 74-91`; `AutomationRuleForm.php:196, 271, 278, 290` | VERIFIED |
| N levels above (`levels_up:N`) | **Only N = 2 is offered** in every select. Validation accepts 1–5 for notify and owner, but **only 2 for escalation**. Levels count from the record's recruiter, not from absolute tiers | `RecipientResolver.php:30, 42, 48, 63`; `AutomationRuleValidator.php:255` | VERIFIED |
| Role-based recipients | **Four fixed targets only:** `role:assistant_manager`, `role:manager`, `role:vp_hr`, `role:chro`. Matched by role **name**, among the recruiter's ancestors only | `RecipientResolver.php:31-34, 64-65`; `HasRoles.php:372-376` | VERIFIED |
| Custom (tenant-created) roles | **NO.** Validation rejects them and the selects cannot offer them | `RecipientResolver.php:48`; `NotifyAction.php:43`; `CreateRecruiterActionAction.php:146`; `AutomationRuleValidator.php:255` | VERIFIED |
| Escalation chains | **YES**, up to 5 steps, each with its own delay, unit, target, priority, optional action and candidate message, plus stop conditions. Every step's delay counts from the run, not the previous step. A step that resolves to nobody is marked **Failed** and does not climb further | `AutomationRuleForm.php:183-204`; `AutomationRuleValidator.php:28, 243-273`; `EscalationService.php:52, 151-198` | VERIFIED |
| Recipients of the built-in hourly alerts | **NO.** They are the record's recruiter, interviewer, `manager ?? creator`, or `reports_to`. Thresholds are editable. Seven checks can be replaced by an active template rule; the other seven cannot be rerouted or switched off | `DispatchRecruitmentAlerts.php:73-81, 107-580`; `ManageRecruitmentConfiguration.php:116-127` | VERIFIED |
| Built-in templates | Installing one creates an ordinary Draft rule that can be fully edited. Only `offer_pending_escalation` is tied to a seeded role (`role:vp_hr`) | `AutomationTemplateCatalog.php:109`; `AutomationRuleService.php:281-298` | VERIFIED |

### 4.2 Defects that matter for custom organisations

1. **`role:*` targets match the editable role name, not the immutable key** (`RecipientResolver.php:65`).
   - **Renaming the seeded `vp_hr` role to "HR Director" silently breaks every `role:vp_hr` escalation**, including the shipped template.
   - A custom role that takes the old name would receive the escalations instead.
   - This is the only string-name `hasRole()` in `app/`. It breaks the project rule that roles are identified by key (`.ai/rules/identity.md`).
   - The tests use seeded names, where name = key, so they cannot tell the two apart (`AutomationEscalationTest.php:60-70, 142`).
   - Status: VERIFIED code; runtime effect INFERENCE.
2. **When every action in a run fails, the escalation is not scheduled** (`AutomationEngine.php:576`). A notify step pointed at a role that resolves to nobody therefore silences the whole chain. VERIFIED.
3. **Three different starting points for requisition events.** VERIFIED:
   - the `requisition_manager` target uses `manager ?? hiringManager` (`RecipientResolver.php:61`);
   - the chain base uses `manager` only (`:88`);
   - the built-in vacancy alert uses `manager ?? createdBy` (`DispatchRecruitmentAlerts.php:107`).
4. **The dry run does not resolve notify or owner recipients**, only escalation steps (`AutomationEngine.php:336-351`). A mis-targeted rule therefore looks fine in preview. VERIFIED.
5. **`template_key` survives edits and duplication** (`AutomationRuleService.php:256, 324-328`). An edited template rule keeps suppressing the built-in alert it replaced. VERIFIED code; impact INFERENCE.

### 4.3 Classified automation dependencies (summary)

| Dependency | Class |
|---|---|
| Four `role:*` targets (closed list, name match) | E + H |
| Escalation whitelist with `levels_up:2` only | E + H |
| `offer_pending_escalation` → `role:vp_hr` | E (editable after install) |
| Self-activation exemption `chro`/`vp_hr` by key | B + C + E |
| `manager_id`-driven starting points and alerts | E + G |
| Requisition scope subsets in `AutomationScopeResolver` / dry run | B + E |
| `reports_to`, `levels_up`, `recruiter`, `interviewer` targets; "team below target" alert to `reports_to` | D, portable |
| Target labels ("VP HR (in hierarchy)" …), template descriptions | A |
| Seeded automation permissions (`vp_hr` full, `manager` all but organisation, `assistant_manager` view, `recruiter` none) | B (default, editable) |

---

## 5. Approval workflows

**Key:**
- **P** permission;
- **H** hierarchy scope;
- **R** hard-coded role key;
- **E** explicit employee on the record;
- **F** fixed database field.

| Decision | Approver determined by | Self-approval | Steps | Seeded roles assumed? | Evidence |
|---|---|---|---|---|---|
| Requisition approve / send back | P `requisitions.approve` (service) + H (policy, via `involvedEmployeeIds`) | Requester blocked (not when `created_by` is null) | **Single**. No approver notification | No | `RequisitionApprovalService.php:103-131, 158-183`; `RecruitmentRequisitionPolicy.php:39-42, 80-90` |
| Offer release / revision release | P `offers.release` + H on the application's recruiter | **Not blocked** (open decision D8.10-008) | Single | No | `OfferService.php:155-157, 259-322, 446-449`; `OfferPolicy.php:36-44` |
| Recruiter incentive / referral bonus | P `incentives.approve`, `incentives.pay` + H on the beneficiary (policy only) | Beneficiary blocked | Status chain. Approve and mark-payable use the same permission | No | `IncentiveApprovalService.php:35-107`; `RecruiterIncentiveCalculationPolicy.php:32-94` |
| Employee separation | P `employees.separation.*` + H; last-CHRO protection by **R** | Not your own | Single | CHRO key | `EmployeeLifecycleService.php:130-254`; `AuthorityGuard.php:168-200` |
| Automation rule activation | P `automation.activate` + scope | Author ≠ activator, **except R `chro`, `vp_hr`** | Two-person, single step | **Yes** (`chro`, `vp_hr`) | `AutomationRuleService.php:34, 152-156, 386-397` |
| Interview feedback / Select | **E** assigned interviewer, or P `interviews.manage` + H. Select = P `pipeline.transition` + H | — | Single; no hiring-manager sign-off | No | `InterviewFeedbackService.php:32-35, 118-122`; `CandidateApplicationPolicy.php:36-39` |
| Joining confirm / employee conversion | P `joining.confirm` / `employees.convert` + H. The default manager comes from **F** requisition slots | — | Single | No | `CandidateJoiningService.php:62-63`; `EmployeeConversionService.php:52-69` |
| Referral review | P `referrals.review` + scope. The notified people are **F** (`manager_id`) + recruiters | Referrer blocked | Single | No | `EmployeeReferralPolicy.php:35-40`; `NotifyReviewersOfReferral.php:28-29` |
| Settings, pipeline templates, communication templates, outcome insights, recruitment costs | P only (`settings.manage`, `pipeline.configure`, `communications.templates`, `outcomes.review`) | — | Single, no approval step | No | respective policies |
| AI action confirmation | P `ai.actions.execute` + the tool's permission | **Requester-only** (self-confirmation) | Single | No | `ActionExecutor.php:58-100, 221-231`; `ConfirmationGate.php:54-64` |
| Export | No approval ("not decided, so not implemented"). Owner-only download | — | None | No | `ExportGovernance.php:12-19`; `ExportPolicy.php:18-30` |
| Tenant ownership transfer | Platform operator; the new owner must hold **R** `chro` | — | Single | **Yes** (CHRO) | `TenantOwnershipService.php:37-74` |
| Role assignment | P `users.manage` / `roles.manage` + H. A protected role only by a holder | Not own roles | Single | CHRO (protected) | `RoleAssignmentService.php:66-87, 169, 224-266` |
| Departed employee's work handoff | P `users.access.manage`. Blocked while **F** requisition slots still name the person | — | Single | No | `OwnershipHandoffService.php:101-125` |

**Findings:**
- **No approval names VP HR, Manager, Assistant Manager or Recruiter as the approver.** VERIFIED.
- **No multi-level or sequential chain exists anywhere** (no Manager → VP HR → CHRO). A configurable chain was considered and left open (D8.10-008 option d, `phase-8-10-decision-register.md:222-227`). VERIFIED / RECORDED.
- **Hard-coded role keys appear in exactly two approval decisions:** the automation self-activation exemption (`chro`, `vp_hr`) and the tenant owner (`chro`). VERIFIED.
- **Seeded defaults** (editable): `requisitions.approve` is held by `chro` and `vp_hr`, not `manager`; `incentives.pay` and `settings.manage` by `chro` only. VERIFIED.

---

## 6. Reporting, analytics and dashboards

**Verdict: nothing depends on the five-level model.** No dashboard, widget, report, KPI, metric definition, target, leaderboard or incentive calculation does any of the following:
- checks a role key;
- groups by `vp_hr_id`, `manager_id` or `assistant_manager_id`;
- picks widgets by role.

VERIFIED (grep of `app/Filament/Pages`, `app/Filament/Widgets`, `app/Services/Metrics`, `config/metrics.php`, `config/outcomes.php`, `app/Services/Governance`, `app/Services/Export`).

| Question | Answer | Evidence |
|---|---|---|
| "Team" | Closure descendants of the viewer, including the viewer, at any depth. Org-wide = `hierarchy.view-all` | `HierarchyService.php:32-43`; `MetricScope.php:43-54` |
| Dashboard per role? | **No.** One widget set; only the order changes, by "do I see ≤ 1 employee" | `Dashboard.php:115-167` |
| Widget and page visibility | **Permissions only**: `performance.view`, `incentives.view`, `compensation.view`, `reports.export`, `automation.analytics`, `outcomes.view` … | `AuthorizesWidget.php:15-23`; page `canAccess()` methods |
| Manager / VP HR / CHRO metrics | **None exist.** `team.outcomes` is the same metric with the viewer's scope | `Metrics/Definitions/TeamOutcomes.php:25-68` |
| Recruiter metrics | Keyed by `recruiter_id` (any employee) or the actor | `RecruiterOutcomes.php`; `RecruiterActivity.php`; `RecruiterDailyMetricsService.php:55-97` |
| Escalation metrics | Counts only (`escalations_sent`, `escalations_resolved_first`). The escalation list shows the hard-coded target labels | `RecruitmentAnalyticsService.php:924-946`; `AutomationEscalationResource.php:66` |
| Daily targets | Employee → designation → department. **No role tier.** Designation targets can therefore tell a "Senior Recruiter" from a "Recruiter" | `TargetResolutionService.php:101-121` |
| Leaderboard population | Anyone who has ever owned an application in scope (no role filter) | `Leaderboard.php:223-228`; `PerformanceEngine.php:173-181` |
| Incentives | Beneficiary = the application owner or a referrer. **No manager or team-lead override incentive** | `IncentiveBeneficiary.php:14-15`; `RecruiterIncentiveCalculator.php:215` |

**Gaps (not dependencies on the five-level model):**
- Labels say "Recruiter" where the population is "application owners", including managers. (A)
- There is no team or manager breakdown dimension; breakdowns are by recruiter, requisition or source only (`RecruitmentAnalyticsService.php:367-371`). (F)
- "Team" in the scheduled team-below-target alert means **direct reports**, while dashboards use the whole subtree (`DispatchRecruitmentAlerts.php:558-576`). (F)
- Performance weightings are tenant-wide, not per designation (`PerformanceEngine.php:36-39`). (F)
- The org-chart "Vacancies" drill-down and the requisition filter use `manager_id` only. (F)
- Designation-level targets need `hierarchy.view-all`, which is seeded to `chro` only (`RecruitmentDailyTarget.php:80-91`). (B, editable)

---

## 7. AI

**Verdict:** no prompt, context builder or RAG component states or implies the CHRO → VP HR → … chain.
- There are no role keys, no `hasRole` / `Role::byKey`, and no org-chart text in `app/Services/AI/**`, `app/Services/Intelligence/**`, `NextBestAction/**`, `Outcomes/**`, `AiAssistantService`, `AiCopilot`, the Copilot view or `config/ai.php`. VERIFIED.
- **Scope:** `ScopesToHierarchy` → `HierarchyService` (`Tools/Concerns/ScopesToHierarchy.php:21-65`).
- **Tool access:** permission strings only (`ToolRegistry.php:147-161`; all 49 tools).
- **Approvals:** requester plus permissions (`ActionExecutor.php:221-231`).
- **Context:** the user's roles are injected as their **editable display names** (`ConversationContextBuilder.php:117, 124`), so the prompt is data-driven. For a default tenant the model sees raw keys such as `vp_hr`.
- **RAG:** filters by tenant and publish status only. It has no role or audience filter (`Rag/VectorSearch.php:68-88`).

**Where the AI still carries the fixed model:**

| # | Location | Effect | Class | Label |
|---|---|---|---|---|
| S1 | `AiProjector.php:214-215`; `GetRequisitionTool.php:51, 66` | `get_requisition` always outputs `manager_ref` and **`vp_hr_ref`**. The hiring, reporting and assistant-manager slots are never sent. An org without a VP HR shows the model an empty field labelled "VP HR". An org that uses "Hiring Manager" gives the model no owner | A + D (root G) | VERIFIED; model behaviour INFERENCE |
| S2 | `SearchRequisitionsTool.php:66-72` | Its own visibility rule leaves out `reporting_manager_id` and `hiring_manager_id`. A hiring or reporting manager without view-all sees fewer requisitions in AI search than in the UI. It hides records rather than leaking them | D + B | VERIFIED |
| S3 | `RecruitmentInsightsService.php:50, 90`; `GenerateDashboardInsightsTool.php:28` | The prompt calls the viewer "this recruiter" even when the data covers a team or the whole org | A | VERIFIED |
| S4 | `ConversationContextBuilder.php:193-194`; `AiCopilot.php:198-199` | "Ask AI" from any Employee page treats that employee as "this recruiter" | A | VERIFIED |
| S5 | `HiringRiskRadar.php:284`; `NextBestActionService.php:177` | The owner falls back to `manager_id` only. If the org uses the hiring or reporting manager slot instead, no Action Center item is created for High or Critical requisition risks | E + D | VERIFIED; impact INFERENCE |
| S6 | `AiOrchestrator.php:176`; `ai-copilot.blade.php:93` | "not available for your role" when the check is actually a permission; "waiting for someone with action-approval permission" when only the requester can approve | A | VERIFIED |

**Default permission packaging:** `ai.actions.execute` is seeded to `chro`, `vp_hr` and `manager`; `ai.conversations.view` to `chro` and `vp_hr` (`RolePermissionSeeder.php:106-163, 245-270`). It can be re-granted to custom roles. VERIFIED.

---

## 8. Data migration: single-tenant RMS → SaaS Tenant #1

### 8.1 Expected failure on the `9cba8e3` path

**Finding: a direct upgrade from `9cba8e3` (75 migrations) to HEAD (179) is expected to abort in `2026_09_25_135221_grant_phase_four_permissions`. INFERENCE, high confidence; the code path is VERIFIED; never executed.**

- `9cba8e3`'s `RolePermissionSeeder` creates `chro`, `vp_hr`, `manager`, `assistant_manager` and `recruiter`, and **no `employee` role** (`git show 9cba8e3:database/seeders/RolePermissionSeeder.php:59-91`). No other code at `9cba8e3` creates roles. VERIFIED.
- The migration grants by name. When roles exist but `employee` does not, it calls `Spatie\Permission\Models\Role::findOrCreate('employee')` (`grant_phase_four_permissions.php:6, 24-28`). VERIFIED.
- HEAD runs with `config('permission.teams') === true` and `team_foreign_key = tenant_id` (`config/permission.php:114, 153`). Nothing in `app/` turns teams off. VERIFIED.
- With teams on, spatie's `findByParam` adds `where (tenant_id is null or tenant_id = ?)` (`vendor/spatie/laravel-permission/src/Models/Role.php:182-193`). VERIFIED.
- `roles.tenant_id` does not exist until `2026_10_04_025113`, so the query fails with an unknown-column error. INFERENCE.
- The earlier migrations in the same run stay applied, because MySQL DDL is not transactional. The documented recovery is to restore the backup (`docs/runbooks/failed-migration.md`). RECORDED.
- **Why tests do not catch it:** tests migrate a fresh database, where the roles table is empty and the branch never runs. No test runs this migration against existing roles. VERIFIED.
- **Why it does not happen from `226bc7d`:** Phase 4 ran there while `teams` was `false` (`git show 226bc7d:config/permission.php:151`). VERIFIED.
- **It is avoided if** Client #1 created an `employee` role themselves. UNKNOWN.
- **No rehearsal has started from 75 migrations** (`docs/saas-1-migration-plan.md:100-101`). RECORDED.

### 8.2 Entity outcome

The status is the same for both starting points unless stated. It assumes the run completes, i.e. §8.1 is avoided or resolved.

| Entity | Status | Responsible migrations / code | Explanation | Label |
|---|---|---|---|---|
| employees | **PRESERVED + BACKFILLED** | `025113` (nullable `tenant_id`); `025114` + `TenantBackfill` (batched by id); `025116` (NOT NULL; `email` and `employee_code` unique per tenant; department and designation composite FKs; `reports_to_id` checked as same-tenant) | Rows, ids, codes and `reports_to_id` are untouched ("Business codes … are never regenerated", `025114:134`) | VERIFIED |
| users | **PRESERVED + TRANSFORMED + BACKFILLED** | `TenantBackfill::createMemberships`; `070951`; `070954` | Users stay global; `email` stays globally unique. Each user gets one **active** Tenant #1 membership carrying their `employee_id`. Then `users.employee_id`, the `access_*` columns and `revoked_roles` are **dropped**, after their values are copied and validated (`070954:21-47`). From `9cba8e3` those access columns only ever held defaults, so everyone is `active` until `identity:reconcile-access` runs | VERIFIED |
| employee_hierarchy | **PRESERVED + BACKFILLED** | `TenantBackfill` (by `ancestor_id` range); `025116` composite FKs | **Never rebuilt and never checked against `reports_to_id`** by any migration | VERIFIED |
| roles | **PRESERVED + BACKFILLED + TRANSFORMED**; from `9cba8e3` the run aborts first (§8.1) | `204338` (key = name **only for exact seeded names**; `is_protected` for `chro`); `025114`; `025116` (per-tenant unique key and name) | Every role goes to Tenant #1, whatever its name. Renamed or custom roles stay **keyless** | VERIFIED |
| permissions (`role_has_permissions`) | **PRESERVED + additive grants** | Grant migrations for phases 4–8.5, billing | Never synced; customised roles keep everything. **Renamed and custom roles receive no new-phase permissions** except possibly `compensation.view` (8.5 grants it by capability) | VERIFIED |
| role assignments (`model_has_roles`) | **PRESERVED + BACKFILLED + TRANSFORMED** | `025113`; `TenantBackfill` (by `model_id`); `025116` (primary key includes `tenant_id`; composite FK to roles) | No morph-map change, so `model_type` stays valid | VERIFIED |
| departments | **PRESERVED + BACKFILLED** | `025113`–`025116` (`code` unique per tenant) | — | VERIFIED |
| designations | **PRESERVED + BACKFILLED** | as departments; `department_id` checked | — | VERIFIED |
| requisitions (incl. five slots, recruiters pivot) | **PRESERVED + BACKFILLED** | `025116` (`code` per tenant; five slots, `created_by` and `location_id` checked as same-tenant; pivot composite FKs); `134640`; `073511` | Slot values are untouched. Pipeline columns stay NULL until `recruitment:assign-default-pipelines`. `closed_at` is never backfilled | VERIFIED |
| applications | **PRESERVED + BACKFILLED** | `025116`; `134640`; `072753` | `pipeline_stage_id` stays NULL until assign-default-pipelines | VERIFIED |
| candidates | **PRESERVED + BACKFILLED** | `140258` (normalised identity columns filled row by row); `025116`; `153040` | No preference rows created from `9cba8e3` (portal accounts table empty) | VERIFIED / INFERENCE |
| interviews (+ feedback) | **PRESERVED + BACKFILLED**; feedback **TRANSFORMED** | `025116`; `134408` | Existing feedback becomes version 1, current, unlocked, `submitted_by` NULL | VERIFIED |
| offers | **PRESERVED + BACKFILLED**; letter fidelity **POTENTIAL LOSS** | `025116`; `135649`; `105830` | `offer_letter_body` and the template id are kept. Pre-8.6 offers get no `offer_letters` row: "their download is regenerated and labelled as such" (`105830:11-12`). The letter as originally issued cannot be reproduced | VERIFIED / RECORDED |
| joining (`candidate_joinings`) | **PRESERVED + BACKFILLED** | `025116` | — | VERIFIED |
| documents: rows | **PRESERVED + BACKFILLED** | `025116` | — | VERIFIED |
| documents: files on disk | **PRESERVED in place** | none (`TenantStorage.php:12-13`: files stored before SaaS-1 keep their path) | Lost only if the storage volume is not carried over with the database | VERIFIED; volume UNKNOWN |
| documents: employee photos | **PRESERVED**; moved later by hand | `files:privatize-employee-photos` (operator command) | Read from the public disk until the command runs. The step is not in the production checklist or Stage 1 | VERIFIED |
| audit logs | **PRESERVED + BACKFILLED** (`tenant_id` stays nullable) | `025113`; `025114` | Old rows have NULL actor, request id and reason. The append-only triggers are not installed by any migration (`audit:protect`) | VERIFIED |

### 8.3 Other outcomes

- **Tenant #1 is created from `TENANT_ONE_*`** (`config/tenancy.php:16-24`).
  - Unset values fall back to defaults silently: slug `main`, name from `APP_COMPANY_NAME`/`APP_NAME`, `Asia/Kolkata`, `en`, `INR`, `IN`.
  - The slug is not validated.
  - It is created only when the database holds organisation data (`TenantBackfill::hasOrganisationData`).
  - VERIFIED.
- **Tenant #1 gets no owner.** `is_owner` is added (`100354:107-108`) and never set.
  - The owner must be set with `tenants:owner`, which requires an active member holding the role **keyed** `chro` (`TenantOwnershipService.php:68-74`).
  - If Client #1's CHRO role was renamed or recreated, it has no key. The command then fails with "The chro role does not exist", and the key cannot be set in the UI.
  - VERIFIED. Also RECORDED as PRC-11 (`production-readiness-code-closure.md:38`).
- **Unkeyed CHRO consequences.** VERIFIED code; effects INFERENCE:
  - last-CHRO protection and the sole-CHRO exemption do nothing (`AuthorityGuard.php:96-107, 178-184`; `EmploymentGate.php:60-66`);
  - `identity:audit` reports `protected_role_missing` and `no_effective_chro`;
  - MFA still applies through privileged permissions.
- **Users with no role** become members but cannot enter Tenant #1 (`StaffAccessService.php:166-175`). INFERENCE.
- **"Hard-coded hierarchy":**
  - The migrations carry over only what is in `employees.reports_to_id` and `employee_hierarchy`.
  - If Client #1 encoded reporting lines in **code**, deploying HEAD replaces that code, and the lines are lost unless they are first written into `reports_to_id`.
  - If they wrote rows **directly** (raw SQL), the closure table may be out of step. `identity:audit` detects only depth-1 drift, and **no rebuild command exists**.
  - The only repair path is moving people one at a time through the org chart, which cannot recreate a missing self row.
  - VERIFIED / INFERENCE.
- **Not run by any migration, and missing from the Stage-1 rehearsal checklist:** `identity:audit`, `identity:reconcile-access`, `recruitment:assign-default-pipelines`, `files:privatize-employee-photos`, `lifecycle:audit`, `audit:protect` (the last is in the checklist as a gap). VERIFIED by grep of `docs/production-bring-up-stage-1.md` and `docs/production-*.md`.
- **Schema-drift abort points** if the client's database has local changes. INFERENCE:
  - `025116` throws "No foreign key on {table}.{column} to replace" when an expected FK is missing (`:604-620`).
  - It drops indexes by fixed name (`:527-528, :558`).
- **Rollback:** 16 migrations in the delta have an empty `down()`, including the backfill, the enforcement and every grant. The documented rollback is to **restore the pre-release backup**, never `migrate:rollback`. No backup system exists yet (`docs/runbooks/failed-migration.md:5-38`). RECORDED.

### 8.4 What is proven

| Claim | Evidence | Label |
|---|---|---|
| Backfill and enforcement are batched, resumable and idempotent | Code (`TenantBackfill.php`) | VERIFIED (code) |
| Rehearsed on development (1,387 rows) and synthetic (100k candidates, 678k rows) data, starting from **160 of 164** migrations | `saas-1-migration-plan.md:89-101`; `saas-7-migration-plan.md` §4 | RECORDED |
| Timings: backfill 0.8 s / 50 s, validate + contract 18 s / 43 s (SaaS-1 table). Elsewhere: "57 s / 47 s" | `saas-1-migration-plan.md:92-93`; `production-bring-up-stage-1.md:520`; `production-readiness-discovery.md:190` | RECORDED. The figures disagree and their source data set is UNKNOWN |
| An automated test of the legacy → Tenant #1 upgrade | none. No test runs `025114`, `025116` on legacy data, `070951`, `070954`, `204338`, `100355`, or Phase 4 with existing roles | VERIFIED (absence) |
| An upgrade from 75 migrations, or on production data | none | RECORDED as not rehearsed |

---

## 9. Real client compatibility

**Configuration strategies available without code:**
1. **Map people onto the seeded roles and keep the role names.** Everything works. Titles shown are the seeded ones: raw keys such as `vp_hr`, and the requisition slot labels.
2. **Rename the seeded roles to the organisation's titles.** Key-based rules survive: MFA, the self-activation exemption, CHRO protection and ownership. **`role:*` automation targets stop matching** (§4.2-1).
3. **Create custom roles.** They are keyless, so they are outside every key-based rule and cannot be automation targets.

Common to all strategies:
- Reporting lines, visibility, approvals by permission, reporting, targets by designation and AI scope work at any depth with any titles (§1, §5, §6, §7).
- The org chart header, the requisition slot labels and the target labels remain fixed text (A).

| | **A.** CHRO → VP HR → Manager → Assistant Manager → Recruiter | **B.** CEO → HR Director → TA Director → Team Lead → Recruiter | **C.** Chief People Officer → TA Head → Senior Recruiter → Recruiter | **D.** HR Director → Recruitment Manager → Recruiter |
|---|---|---|---|---|
| Hierarchy and visibility | Native | Works (5 levels). If the CEO is not a user, the HR Director needs `hierarchy.view-all` | Works | Works |
| Roles | Native (display names are raw keys until renamed) | Strategy 1 maps 1:1 but shows the wrong titles. Strategies 2 and 3 show correct titles but lose role-targeted escalation | CPO = `chro`, renamed (safe). TA Head and Senior Recruiter need strategy 1 for role targets | HRD = `chro`, RM = `manager`, Rec = `recruiter`. All can be renamed safely, because D needs no `role:*` target |
| Requisitions | Native | Three people only fit slots labelled "VP HR", "Manager", "Assistant Manager". Alerts follow "Manager" (§3.4) | TA Head must sit in "Manager" (or "VP HR") to drive alerts. One slot unused | RM in "Manager" (title matches). "VP HR" and "Assistant Manager" left empty, with no loss |
| Approvals | Native | Grant `requisitions.approve` and others to the HR Director's role. Single-step only | Same | Same |
| Automation | Native | `reports_to` = Team Lead, `levels_up:2` = TA Director. **The HR Director, three levels up, can be reached only by `role:vp_hr`**, which needs strategy 1 | `reports_to` / `levels_up:2` reach Senior Recruiter / TA Head. **The CPO, three up, is reachable only by `role:chro` with the name unchanged.** Targets shift when a Senior Recruiter owns the application | `reports_to` + `levels_up:2` cover the whole org. Edit the shipped template's `role:vp_hr` step |
| Reporting | Native | Works. No rollups by TA Director or Team Lead; no override incentives | Works. Designation targets separate Senior from Recruiter. One leaderboard for both | Works |
| AI | Native | `vp_hr_ref` holds the HR Director, if recorded there | `vp_hr_ref` usually null | `vp_hr_ref` null |
| **Without code changes** | **YES** | **PARTIALLY**: functional only by presenting people under seeded titles | **PARTIALLY**: escalation to the top requires keeping the seeded name `chro` | **YES**, with fixed-text leftovers and one template edit |

---

## 10. Classification

| | Rating |
|---|---|
| A. Reporting hierarchy | **FULLY CONFIGURABLE** |
| B. Roles | **PARTIALLY CONFIGURABLE** |
| C. Workflow responsibility | **PARTIALLY CONFIGURABLE** |
| D. Approvals | **PARTIALLY CONFIGURABLE** |
| E. Automation | **PARTIALLY CONFIGURABLE** |
| F. Reporting | **FULLY CONFIGURABLE** (display caveats, §6) |
| G. AI | **PARTIALLY CONFIGURABLE** |
| H. Migration from old RMS | **NOT READY** (from `9cba8e3`); **REQUIRES REHEARSAL** (from `226bc7d`) |

---

## 11. Critical question 1

> "If Client #2 has a completely different HR/recruitment organizational structure, can an administrator configure Recruitment Edge for that organization entirely through the UI without developer intervention?"

**PARTIALLY.**

**What the administrator can do in the UI:**
- build the real reporting tree, at any depth and with several roots;
- create and rename roles and choose their permissions;
- give anyone org-wide visibility through `hierarchy.view-all`;
- set targets by designation;
- grant approval permissions to any role;
- build escalations relative to the recruiter (`reports_to`, `levels_up:2`).

Visibility, approvals, reporting and AI scope then follow that structure.

**What needs a developer** (or the client accepting the seeded titles):
1. **Requisition slot labels.** "VP HR", "Manager" and "Assistant Manager" are fixed, and alerts and automation follow the "Manager" slot.
2. **Role-targeted automation.** Only the four seeded role **names** can be targeted. Renaming those roles breaks the targets, custom roles cannot be targeted, and escalation by count stops at two levels.
3. **Key-based identity rules.** MFA-by-role and the automation self-activation exemption apply only to the seeded keys, and a custom role can never get a key through the UI.
4. **Fixed text.** The org chart header, the target labels and the template wording.

**An organisation of three levels or fewer, or of five levels mapped 1:1, can run without code changes but with mislabelled screens. An organisation that needs role-targeted escalation beyond two levels under its own role names cannot.**

---

## 12. Critical question 2

> "Can Client #1's existing hardcoded hierarchy/data be migrated into Tenant #1 while preserving their existing employees, reporting relationships, roles, permissions, candidates, requisitions and historical records?"

**PARTIALLY.**

**Proven by reading the code** (VERIFIED):
- The migrations are designed to keep every row, id and business code: employees and `reports_to_id`, the closure table, roles, role assignments, permissions (additive only), departments, designations, requisitions with all five slots, applications, candidates, interviews, offers, joinings, document rows and files in place, and audit logs.
- Every row is assigned to Tenant #1.
- Every user becomes a Tenant #1 member with their roles.
- Duplicate-key, foreign-key and same-tenant checks run during enforcement.

**Proven by rehearsal** (RECORDED): only from 160 of 164 migrations, on development and synthetic data.

**Not proven, and the reasons:**
1. **From `9cba8e3` the run is expected to abort at the Phase 4 grant** (§8.1), unless an `employee` role already exists. **This must be confirmed on a production copy before any release date.**
2. **Client #1's deployed code and schema are UNKNOWN.** If their schema has local changes, enforcement can abort part-way (§8.3).
3. **Renamed or custom roles stay keyless.** They receive no new-phase permissions. If the CHRO role is among them, the Tenant #1 owner cannot be set without a manual data fix (§8.3).
4. **"Hard-coded" hierarchy is preserved only if it is in `employees.reports_to_id` and a consistent `employee_hierarchy`.** Hierarchy encoded in code is replaced by HEAD. Raw-SQL drift is detected only at depth 1, and there is no rebuild command.
5. **Some outcomes are not identical to the source:**
   - Offer letters issued before Phase 8.6 are regenerated, not reproduced.
   - Access state from `9cba8e3` becomes `active` for everyone until reconciled.
   - Pending AI actions expire.
   - Pipeline stage columns stay NULL until a command runs.
6. **Never measured on Client #1's data volume.**

**A rehearsal on a restored production copy must show:**
- the starting line (fingerprint and migration count);
- that all 179 migrations complete;
- the row-count baseline unchanged except for expected changes;
- `ops:verify-integrity`, `tenancy:verify`, `identity:audit` and `lifecycle:audit` clean;
- every role keyed as intended;
- a keyed CHRO, and `tenants:owner` succeeding;
- spot checks of reporting lines against the client's org chart;
- the run time.

---

## 13. Top 10 hard-coded dependencies

| # | Dependency | Where | Class |
|---|---|---|---|
| 1 | `role:*` targets matched by editable role **name**; a rename breaks escalations silently | `RecipientResolver.php:64-65` | E + H |
| 2 | Closed target list: four seeded roles, `levels_up:2` only for escalation, no custom roles | `RecipientResolver.php:25-48`; `AutomationRuleValidator.php:255`; `EscalateAction.php:36` | E + H |
| 3 | Five fixed level-named requisition slots with fixed labels | `create_recruitment_requisitions_table.php:29-33`; `RecruitmentRequisitionForm.php:86-115` | G + A |
| 4 | Requisition alerts, automation, referral and owner logic follow `manager_id` only | `RecipientResolver.php:61, 88`; `DispatchRecruitmentAlerts.php:107`; `NotifyReviewersOfReferral.php:29`; `HiringRiskRadar.php:284`; `NextBestActionService.php:177` | E + C |
| 5 | UI-created roles never get a key, so key-based rules ignore them | `RoleAssignmentService.php:149`; `RoleForm.php:27-32` | B + H |
| 6 | Automation self-activation exemption hard-coded to keys `chro`, `vp_hr` | `AutomationRuleService.php:34, 388` | B + E |
| 7 | CHRO key is the super-admin and owner anchor (`*`, protected, required for the tenant owner) | `config/identity.php:22, 29`; `TenantInvitationService.php:117, 541`; `TenantOwnershipService.php:72` | B |
| 8 | Base role `employee` required by key but deletable | `config/identity.php:26`; `RolePolicy.php:41`; `TenantInvitationService.php:105`; `StaffAccessService.php:262` | B + C + H (latent) |
| 9 | MFA-by-role list keyed `chro`, `vp_hr`, `manager` | `config/identity.php:53`; `MfaService.php:55-72` | B |
| 10 | Six-role bundle (display name = raw key) provisioned into every tenant; shipped template escalates to `role:vp_hr` | `RolePermissionSeeder.php:105-339` via `TenantDefaults.php:19`; `AutomationTemplateCatalog.php:109` | B + E |

**Also notable:**
- requisition-visibility subsets that disagree with `scopeVisibleTo` (`SearchRequisitionsTool`, `AutomationScopeResolver`, `DryRunAutomationRule`, `OwnershipHandoffService`);
- the AI `vp_hr_ref` contract;
- the fixed org chart header.

---

## 14. Production blockers arising from this audit

**Client #1 upgrade (existing):**

| # | Blocker | Status |
|---|---|---|
| PB-1 | Expected abort of `grant_phase_four_permissions` on a `9cba8e3` start (§8.1) | **New finding.** INFERENCE, high confidence. Not fixed (audit only). Must be confirmed or ruled out on a production copy |
| PB-2 | Client #1's deployed line, schema and data unknown (production facts BU-O15) | Open (`production-bring-up-stage-2b-production-facts.md`) |
| PB-3 | No rehearsal from 75 migrations or on production data; no backup system | Open (Stage-1 gates) |
| PB-4 | `TENANT_ONE_*` values undecided (BU-O20); unset values fall back to defaults silently | Open (owner) |
| PB-5 | Tenant #1 owner: needs a member with a **keyed** CHRO role. If Client #1's CHRO role is renamed or recreated, the owner cannot be set without a manual key fix | Open; depends on Client #1's role names (UNKNOWN) |
| PB-6 | Post-migration steps missing from the Stage-1 rehearsal checklist: `identity:audit`, `identity:reconcile-access`, `recruitment:assign-default-pipelines`, `files:privatize-employee-photos`, `lifecycle:audit`, role-key and CHRO checks | Open (documentation gap) |
| PB-7 | Reporting lines held outside `reports_to_id`, or a drifted closure table: depth-1 detection only, no rebuild command | Open; depends on Client #1's data |

**Selling to a custom-structure client (Client #2)** — not a go-live blocker for Client #1:
- §13 items 1, 2, 3, 5 and 6 are the limits an implementation team will hit first.
- Item 1 is a defect: renaming a seeded role silently breaks escalations. It is the first one a custom-structure tenant would hit.

**Release candidate modified: NO.** This document is the only file added. Nothing was committed or pushed.
