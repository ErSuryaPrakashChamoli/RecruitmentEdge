# SaaS-2 — Security Review: Identity, Membership & Access

**For:** Security, the project owner and Engineering.

**Scope:**
- the brief's §48 review list: authentication, authorisation, tenant switching, membership and invitation lifecycles, role and permission isolation, sessions, MFA, the platform boundary, enumeration, cache isolation, Livewire, Filament, direct routes, concurrency, audit logging;
- on `feature/saas-2-identity-access` (4 commits on top of `0e8d865`);
- verified on SQLite and MySQL 8.4.11.

**Result:**
- **No Critical or High finding remains open.**
- One High was found and fixed: S2-01, credential takeover across tenants. It was latent in SaaS-1 and would have become live with invitations.
- Remaining items are Low or Info, accepted or deferred, each with an owner (§5).

## 1. Bypass attempts (brief §24) and the tests that try them

| Attempt | Result | Test |
|---|---|---|
| Horizontal escalation: B opens Beta (not a member) | 404, same as an unknown tenant | `AuthorizationMatrixTest` (panel matrix), `TenantSwitchingTest` |
| Vertical escalation: A (recruiter in Beta) does manager-only work in Beta | 403; `automation.view` false in Beta, true in Acme | `AuthorizationMatrixTest`, `RoleIsolationTest` |
| Tenant switch attack: change the slug in the URL | 404 for forbidden and unknown slugs alike | `TenantSwitchingTest` |
| Livewire: replay an open page's update after the membership is revoked, or the tenant cancelled | 404 (persistent tenant middleware) | `TenantSwitchingTest` |
| Stale session tenant: the remembered tenant is revoked | not used; the profile falls back to an accessible tenant | `TenantSwitchingTest` |
| Revoked / suspended / removed membership | that tenant closes at once; other tenants keep working; sessions end when nothing is left | `MembershipAccessTest`, `CredentialAuthorityTest` |
| Role leakage: same User object asked in two tenants | Tenant B's answer only; nothing sticks | `RoleIsolationTest` (mutation M3) |
| Permission leakage: direct permission in Beta | absent in Acme | `RoleIsolationTest` |
| Another tenant's role id in a role sync | ignored | `RoleIsolationTest` |
| Invitation replay (used link) | same "can't be used" page | `InvitationLifecycleTest` |
| Invitation race: two accepts / accept vs revoke (both orders) / accept vs tenant suspension / two tenants creating one identity / two invitations into one tenant | one winner; the loser waits on the lock and refuses (MySQL) | `tests/Concurrency/IdentityRaceTest.php` |
| Expired invitation | refused, marked Expired, audited | `InvitationLifecycleTest` |
| Wrong-user acceptance | refused, audited (`wrong_identity`) | `InvitationLifecycleTest` (mutation M4) |
| Wrong-tenant acceptance | impossible: the tenant comes from the invitation (token hash), never the URL | `InvitationLifecycleTest` |
| Suspended / cancelled tenant acceptance | refused (`tenant_unusable`), for admin and conversion invitations | `InvitationLifecycleTest` (mutation M10) |
| Password reset enumeration | the same message for sent, unknown, too-soon and no-tenant addresses | `CredentialAuthorityTest` (mutation M9) |
| Email existence (S1-01) via invite, conversion, profile / admin email change | identical outcomes | `InvitationLifecycleTest`, `CredentialAuthorityTest` (mutation M13) |
| MFA bypass by switching to a tenant without the policy, direct URL, chooser, Livewire | the enrolment page in every case | `MfaPolicyTest` (mutation M5) |
| Turning MFA off while a tenant requires it | refused, in every tenant | `MfaPolicyTest` |
| Credential takeover: Tenant A resets a shared identity's password, email, MFA or sessions | refused, with the fields read-only and a tampered name ignored | `CredentialAuthorityTest` (mutation M7) |
| Support access bypass | a grant opens nothing; it is time-bound, revocable, tenant-specific, and invisible to other tenants | `PlatformBoundaryTest` |
| Platform / tenant boundary | an operator is no member, has no role, gets no tenant and no permission; the tenant plane never consults the platform plane (architecture test) | `PlatformBoundaryTest`, `IdentityPlaneArchitectureTest` |
| Platform-disabled identity | signed out everywhere; joins nothing | `PlatformBoundaryTest`, `InvitationLifecycleTest` |
| Default tenant inaccessible | the chooser, never a silent switch | `TenantSwitchingTest` (mutation M8) |
| Global search | this tenant's people only | `AuthorizationMatrixTest` |

## 2. Findings fixed in SaaS-2

| ID | Severity | Finding | Fix | Proof |
|---|---|---|---|---|
| S2-01 | **High** | A tenant's administrator could set the password of, change the email of, reset the MFA of, or end the sessions of **any** member, including a person who also belongs to another tenant. That is an account takeover usable in the other tenant. Latent in SaaS-1, live as soon as a second membership can exist. | Global credentials are managed only for an identity that belongs to the tenant alone (`AuthorityGuard::assertCanManageCredentials`). The UI fields are read-only, with the reason. | `CredentialAuthorityTest`; mutation M7 |
| S2-02 | Medium (pre-existing) | Staff "Forgot password" revealed unknown addresses ("We can't find a user…") and known ones ("Please wait before retrying"). | `StaffRequestPasswordReset`: one message for every outcome. | `CredentialAuthorityTest`; mutation M9 |
| S2-03 | Medium (S1-01) | Global email existence was revealed by creating a login, by conversion, and by the email-uniqueness check on profile / admin email changes. | Invitations only (the same outcome either way); no form uniqueness check; a taken address silently gets no link. | `InvitationLifecycleTest`, `CredentialAuthorityTest`; mutation M13 |
| S2-04 | Medium | spatie's team-scoped `roles` and `permissions` (and the employee), once loaded on a User, answered in any later tenant of the same process (services, jobs, commands using `TenantContext::run`). | Tenant-dependent relations are discarded on a tenant change (`User::relationLoaded/setRelation`). | `RoleIsolationTest`; mutation M3 |
| S2-05 | Medium | Access state was identity-wide: one tenant's suspension, revocation or separation closed every tenant the person belonged to. SaaS-1 limited this to the "employing tenant". | Access state per membership; employment facts read in their own tenant. | `MembershipAccessTest`; mutation M11 |
| S2-06 | Low | Filament placed a person with several tenants and no usable default into whichever tenant sorted first. | `StaffFilamentManager`, plus the organisation chooser. | `TenantSwitchingTest`; mutation M8 |
| S2-07 | Low | A role granted at the moment of a revocation could land on the revoked membership. It granted no access, but left an inconsistent state. | Role changes lock and re-check the membership. | MySQL race test (fails on the pre-fix code) |
| S2-08 | Low (pre-existing) | Fresh install: the seeded CHRO had no membership, so could reach no tenant. | The seeder creates the membership. | — |
| S2-09 | Low | An in-process access memo survived a tenant status change. A deeper layer still refused (403 instead of 404). | Tenant and membership writes invalidate decisions. | `TenantSwitchingTest` |
| S2-10 | Low | Membership conflict at acceptance surfaced as an error; a platform-disabled identity could be attached (it could not use it). | Clean refusal; disabled identities refused. | `InvitationLifecycleTest` |
| S2-11 | Low (development) | The contract migration's `down()` failed on MySQL. | Index order fixed; rollback and re-apply rehearsed on MySQL. | migration plan §5 |

## 3. Layers and their tests

| Layer | What it guarantees | Tests |
|---|---|---|
| Membership (data) | access state and employee link per tenant; one login per employee (unique key); `tenancy:verify` checks states and links | `MembershipAccessTest`, `TenancyFoundationTest` |
| Gate (`StaffAccessService`) | permission only with an Active membership in a usable tenant, the identity not disabled, and no employment block there | `AuthorizationMatrixTest` (permission matrix), mutation M1 |
| Panel (Filament + middleware) | the URL's tenant is checked against the membership on every request and every Livewire update | `TenantSwitchingTest`, mutation M2 |
| Roles (spatie teams) | only the current tenant's roles count, also on reused objects | `RoleIsolationTest` |
| Invitations | single-use hashed tokens; the invitation's own tenant; atomic | `InvitationLifecycleTest`, `IdentityRaceTest` |
| Credentials | managed only for exclusive identities | `CredentialAuthorityTest` |
| MFA | stricter-of across active memberships | `MfaPolicyTest` |
| Platform | separate, opens nothing | `PlatformBoundaryTest` |
| Construction | no query on dropped identity columns; memberships created only by the identity services; the tenant plane never consults the platform plane; tokens never logged or stored | `IdentityPlaneArchitectureTest` |

## 4. Mutation checks

Each protection was removed in turn; its tests must fail.

| # | Mutation | Caught by |
|---|---|---|
| M1 | access gate ignores the membership state | `AuthorizationMatrixTest` |
| M2 | route tenant trusted without the membership | `TenantSwitchingTest` (4 failing) |
| M3 | tenant-scoped relations reused across tenants | `RoleIsolationTest` (3) |
| M4 | invitation accepted by any identity | `InvitationLifecycleTest` |
| M5 | tenant MFA policy skipped | `MfaPolicyTest` (3) |
| M6 | remembered session tenant not re-checked | `TenantSwitchingTest` |
| M7 | credentials of shared identities managed by any tenant | `CredentialAuthorityTest` (2) |
| M8 | default tenant falls back to the first tenant | `TenantSwitchingTest` (2) |
| M9 | password reset reveals the outcome | `CredentialAuthorityTest` |
| M10 | suspended tenant accepted by an invitation | `InvitationLifecycleTest` (4). The first run was caught only by a deeper layer, so the test was tightened. |
| M11 | suspension in one tenant closes every tenant | `MembershipAccessTest` (4) |
| M12 | invitation token stored in clear | `InvitationLifecycleTest` (5) |
| M13 | an existing email answered differently on invite | `InvitationLifecycleTest` (3) |
| M14 | role change without the membership lock (MySQL) | `IdentityRaceTest` |
| — | a planted `whereNotNull('employee_id')` on a User query | `IdentityPlaneArchitectureTest` |

## 5. Open and accepted items (no Critical, no High)

| ID | Severity | Item | Owner | Destination | Rationale |
|---|---|---|---|---|---|
| S2-A1 | Low | The holder of a valid invitation link learns whether the invited address already has an identity (sign in vs create). | Security | Accepted | The link is a secret sent to that mailbox (D-S2-22). |
| S2-A2 | Low | An administrator sees that their own member also belongs to another organisation (never which). | Security | Accepted | Needed to explain the read-only credentials (D-S2-23). |
| S2-A3 | Low | The invitation token appears once in the web server's access log (the link path). It leaves the URL at once and is single-use. | Engineering | SaaS-5 hardening | Option: exchange the token from a URL fragment by POST. |
| S2-A4 | Low | "Forgot password" for a known address does slightly more database work (the panel check) than for an unknown one. The mail itself is queued. | Engineering | SaaS-7 | A small timing difference; the response is identical. |
| S2-A5 | Info | Platform operators exist only on the command line; there is no platform panel or platform audit viewer (S1-11 partly addressed). | Ops | SaaS-5 | Foundation only by design. |
| S2-A6 | Info | Support grants are recorded but nothing honours them. | Engineering | SaaS-5 | The support console is out of scope. |
| S2-A7 | Info | SSO / OIDC / SAML / SCIM / passkeys / service accounts are not implemented. The identity model accepts them later. | Product | SSO phase | Out of scope (brief §40). |
| S1-10 | Low | spatie's single permission → roles map for all tenants (cache churn). | Engineering | SaaS-7 | Unchanged from SaaS-1. |
| S1-02 … S1-09 | Medium / Low | SaaS-1 findings outside identity. | per SaaS-1 review | SaaS-3 / 5 / 6 / 7 | Not in SaaS-2 scope. |

## 6. Audit coverage (brief §27)

All events are written in the tenant's stream, unless marked platform.
- **Sign-in and sign-out:** `login` and `logout`; pre-tenant events go to every tenant the person is an active member of.
- **Tenant entry:** `tenant_selected`, `tenant_switched`.
- **Membership:** `membership_created`, `membership_activated`, `access_suspended`, `access_revoked`, `access_restored`, `sessions_revoked`.
- **Invitations:** `invitation_created`, `_resent`, `_revoked`, `_accepted`, `_expired`, `_refused` (each with the reason).
- **Roles:** `roles_changed`, `roles_removed`, `roles_assigned`.
- **MFA:** `mfa_policy_changed`.
- **Support access:** `support_access_granted`, `support_access_revoked`.
- **Platform stream:** `platform_role_granted`, `platform_role_revoked`.
- **Identity lock:** `identity_disabled` / `identity_enabled` (every tenant of the person).

No password, OTP, token or session secret is written anywhere; an architecture test enforces this for invitation tokens.

## 7. Verification

All numbers are at commit `8acb32c`; the code is unchanged by the final documentation commit.

| Suite | Result |
|---|---|
| Full suite, SQLite | **2,361 / 2,361**, 27,669 assertions (baseline 2,260 + 101 SaaS-2 tests; existing tests changed on purpose are listed in §8) |
| Full suite, MySQL 8.4.11 (fresh migrations, SaaS-2 migrations included) | **2,361 / 2,361**, 27,669 assertions |
| SaaS-1 tenancy (`tests/Feature/Tenancy`), both databases | 105 / 105 |
| SaaS-2 (`tests/Feature/IdentityAccess` + identity-plane architecture), both databases | 101 / 101 |
| Concurrency, MySQL (`phpunit.concurrency.xml`) | **21 / 21** (14 existing + 7 SaaS-2) |
| Migration rehearsals (MySQL copies) | R1, R1b, R2 clean (`saas-2-migration-plan.md` §5) |
| Browser | not applicable: the repository has no browser suite |

## 8. Existing assertions changed on purpose

| Test | Was | Now | Why |
|---|---|---|---|
| `PanelAccessTest`, `AiCopilotPageAccessTest` (user with no role) | 403 | signed out, redirected to sign-in | D-S2-24: a session with no tenant left ends |
| `CrossTenantPanelTest` (unusable tenant) | 403 / 404 | signed out | the same |
| `CrossTenantPanelTest` (access state of a Beta member) | read in Acme | read in Beta | access state is per membership |
| `CrossTenantPanelTest` (enum) | `TenantMembershipStatus` | `AccessState` | D-S2-03 |
| `ProvisioningAndRehireTest` (conversion) | a login is created at conversion | an invitation is sent; the login exists on acceptance | D-S2-09 (S1-01) |
| `ProvisioningAndRehireTest` (rehire / separation tests) | the login read after conversion | the invitation accepted first | the same |
| `CredentialLifecycleTest` (password policy) | admin creates a login with a weak password | admin sets a weak password on edit | logins are no longer created with a password |
| `CredentialLifecycleTest`, `P810SEC001PasswordLinkOriginTest` | Filament's `RequestPasswordReset` | `StaffRequestPasswordReset` (the page the panel serves) | S2-02 |
| `ProvisioningAndRehireTest`, `TransitionIdempotencyTest` | `User::where('employee_id')` | `User::linkedToEmployee()` | the column moved to the membership |

## 9. What this review does not cover

- Production data and a production copy (migration plan §5).
- Provider-side isolation (S1-02) and fair use (S1-03).
- A real browser: the repository has no browser suite. Livewire and HTTP paths are covered by feature tests.
