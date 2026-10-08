# Calendar OAuth Configuration Diagnostic

**For:** the release owner and the administrator who will register the Google and Microsoft OAuth applications.

**Date:** 2026-10-07.

**Scope:** why `/admin/main/calendar-connections` reports *"This calendar provider is not configured. Ask an administrator to add its OAuth credentials."* for both **Connect Google Calendar** and **Connect Microsoft 365**, and exactly what must be configured.

**Code basis:**
- Frozen release candidate `589c2f0f615952f1c4fed96a45a9e170ed877904`.
- The working tree was at `09bd611`. Between the two commits only `docs/*` and one stray root file (`s-2026-10-06`) differ. `git diff 589c2f0 09bd611 -- app config routes .env.example` is empty, so all code findings apply to the frozen release candidate unchanged.

**Read-only.** Nothing was changed in code, migrations, tests, `.env`, packages, data or git. Credential values were never printed: only their presence was checked.

---

## Summary

| Provider | State | Cause |
|---|---|---|
| Google Calendar | **NOT CONFIGURED** | `GOOGLE_CALENDAR_CLIENT_ID` and `GOOGLE_CALENDAR_CLIENT_SECRET` are not in `.env`, so both `config('services.google_calendar.*')` values are null. |
| Microsoft 365 | **NOT CONFIGURED** | `MICROSOFT_GRAPH_CLIENT_ID` and `MICROSOFT_GRAPH_CLIENT_SECRET` are not in `.env`, so both `config('services.microsoft_graph.*')` values are null. |

The local `.env` has **no lines at all** for these variables. `.env.example` lists them, blank, at lines 158–162. Config is not cached (`bootstrap/cache/config.php` does not exist), so `.env` is read directly.

The button, route, permission and tenant checks all pass for the signed-in user. The request fails at one check only: `isConfigured()`.

---

## A. Google implementation

| Item | Location / value |
|---|---|
| Button | `app/Filament/Resources/CalendarConnections/Pages/ListCalendarConnections.php` builds one header action per key of `CalendarManager::PROVIDERS`. The `google_calendar` action is labelled "Connect Google Calendar" and links to `route('integrations.calendar.connect', 'google_calendar')`. It is shown only if the user has `calendar.connect` and a linked `employee_id`. |
| Connect route | `GET /integrations/calendar/{tenant}/{provider}/connect`, named `integrations.calendar.connect`. Middleware: `auth`, `throttle:calendar-oauth` (20 requests per minute per user), `ResolveTenantFromRoute`. Defined in `routes/web.php`, lines 33–36. |
| Controller | `App\Http\Controllers\Integrations\CalendarOAuthController::redirect()`. |
| Service | `App\Services\Integrations\Calendar\CalendarConnectionService::authorizationRequest()`, which calls `configured()`. |
| Provider lookup | `App\Services\Integrations\Calendar\CalendarManager::find('google_calendar')` returns `GoogleCalendarProvider`. |
| OAuth provider | `App\Services\Integrations\Calendar\Providers\GoogleCalendarProvider`. It uses the OAuth 2.0 authorization-code flow with `access_type=offline` and `prompt=consent`. |
| Authorize endpoint | `https://accounts.google.com/o/oauth2/v2/auth` |
| Token endpoint | `https://oauth2.googleapis.com/token` (code exchange and refresh) |
| Identity lookup | `https://openidconnect.googleapis.com/v1/userinfo` returns the `email` claim. |
| Calendar API | `https://www.googleapis.com/calendar/v3`. Meet links are created through `conferenceData` (`hangoutsMeet`). |
| Config keys | `services.google_calendar.client_id`, `services.google_calendar.client_secret` (`config/services.php`, lines 62–65) |
| Env variables | `GOOGLE_CALENDAR_CLIENT_ID`, `GOOGLE_CALENDAR_CLIENT_SECRET` |
| Callback route | `GET /integrations/calendar/{provider}/callback`, named `integrations.calendar.callback`. Middleware: `auth`, `throttle:calendar-oauth`. The route has no tenant segment. |
| Callback handler | `CalendarOAuthController::callback()`, then `complete()`, then `CalendarConnectionService::complete()`. |
| Redirect URI format | `route('integrations.calendar.callback', 'google_calendar')`, which resolves to `{root}/integrations/calendar/google_calendar/callback`. No redirect-URI env variable exists (see §E). |
| Credential check | `GoogleCalendarProvider::isConfigured()` returns `filled(client_id) && filled(client_secret)`. It checks that values are present, not that they are valid. |

**Why the UI reports "not configured":**
1. `redirect()` calls `authorizationRequest('google_calendar', …)`.
2. `configured()` gets `GoogleCalendarProvider`, and `isConfigured()` returns `false` because both config values are null.
3. `configured()` throws `DomainException('This calendar provider is not configured. Ask an administrator to add its OAuth credentials.')`.
4. The controller catches the exception, sends the message as a danger notification and redirects back to Calendar Connections.

The user never reaches Google.

## B. Microsoft implementation

| Item | Location / value |
|---|---|
| Button | Same page and code as Google, using key `microsoft_calendar`. It is labelled "Connect Microsoft 365" and has the same visibility rule. |
| Connect route | `GET /integrations/calendar/{tenant}/microsoft_calendar/connect`, the same `integrations.calendar.connect` route. |
| Controller / service | `CalendarOAuthController::redirect()`, then `CalendarConnectionService::authorizationRequest()`, then `configured()`. |
| Provider lookup | `CalendarManager::find('microsoft_calendar')` returns `MicrosoftCalendarProvider`. |
| OAuth provider | `App\Services\Integrations\Calendar\Providers\MicrosoftCalendarProvider`. It uses the Microsoft identity platform v2 authorization-code flow with delegated permissions and `response_mode=query`. |
| Authority | `https://login.microsoftonline.com/{services.microsoft_graph.tenant}/oauth2/v2.0`. The tenant defaults to `common`. |
| Authorize / token endpoints | `{authority}/authorize` and `{authority}/token` (code exchange and refresh) |
| Identity lookup | `GET https://graph.microsoft.com/v1.0/me` returns `mail`, or `userPrincipalName` if `mail` is empty. |
| Calendar API | Microsoft Graph v1.0 `/me/events` and `/me/calendar/getSchedule`. Teams links are created through `isOnlineMeeting` with `teamsForBusiness`. |
| Config keys | `services.microsoft_graph.client_id`, `services.microsoft_graph.client_secret`, `services.microsoft_graph.tenant` (`config/services.php`, lines 67–71) |
| Env variables | `MICROSOFT_GRAPH_CLIENT_ID`, `MICROSOFT_GRAPH_CLIENT_SECRET`, `MICROSOFT_GRAPH_TENANT` (defaults to `common`) |
| Callback route | `GET /integrations/calendar/microsoft_calendar/callback`, the same `integrations.calendar.callback` route. |
| Redirect URI format | `{root}/integrations/calendar/microsoft_calendar/callback` |
| Credential check | `MicrosoftCalendarProvider::isConfigured()` returns `filled(client_id) && filled(client_secret)`. The tenant value is not checked. |

**Why the UI reports "not configured":** the same path as Google. `MicrosoftCalendarProvider::isConfigured()` returns `false` because both config values are null, and `configured()` throws the same `DomainException`. This exact behaviour is covered by the test *"an unconfigured provider cannot be connected"* in `tests/Feature/CalendarIntegrationTest.php`.

## C. Required configuration

| Provider | Variable | Required | Current State (local `.env`) | Purpose |
|---|---|---|---|---|
| Google | `GOOGLE_CALENDAR_CLIENT_ID` | Yes | **MISSING** (no line) | OAuth 2.0 Web client ID. Read into `services.google_calendar.client_id`. |
| Google | `GOOGLE_CALENDAR_CLIENT_SECRET` | Yes | **MISSING** (no line) | OAuth 2.0 Web client secret, used for code exchange and token refresh. |
| Microsoft | `MICROSOFT_GRAPH_CLIENT_ID` | Yes | **MISSING** (no line) | Entra app registration's Application (client) ID. |
| Microsoft | `MICROSOFT_GRAPH_CLIENT_SECRET` | Yes | **MISSING** (no line) | Entra client secret **Value** (not the Secret ID). |
| Microsoft | `MICROSOFT_GRAPH_TENANT` | No (default `common`) | **MISSING** (no line). The effective value is the config default `common`. | Authority segment: `common`, `organizations`, `consumers` or a directory (tenant) GUID. It must match the app registration's supported account types. |
| Both | `APP_URL` | Yes in production | PRESENT (`http://localhost:8000`) | Outside `local`, it is the host of the generated callback URL (see §E). |
| Both | `TRUSTED_PROXIES` | Yes in production behind TLS termination | Not set locally (not needed locally) | Lets the app see `https` from `X-Forwarded-Proto`, so the callback scheme is `https` (see §E). |
| Both | `APP_KEY` | Yes (already required) | PRESENT | Encrypts the stored access and refresh tokens. |

**These variables do not exist in the code:** `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_TENANT_ID`, `MICROSOFT_REDIRECT_URI`. Setting them has no effect.

## D. Current configuration state

| Check | Result |
|---|---|
| `.env` present | Yes. `.env` is git-ignored (`.gitignore` line 3) and not tracked. |
| `APP_ENV` | `local` |
| Config cached | No |
| `services.google_calendar.client_id` | EMPTY / NULL |
| `services.google_calendar.client_secret` | EMPTY / NULL |
| `services.microsoft_graph.client_id` | EMPTY / NULL |
| `services.microsoft_graph.client_secret` | EMPTY / NULL |
| `services.microsoft_graph.tenant` | FILLED (config default `common`) |
| `GoogleCalendarProvider::isConfigured()` | `false` |
| `MicrosoftCalendarProvider::isConfigured()` | `false` |
| Tenants | 1 (`main`) |
| `calendar_connections` rows | 0 |
| Session | `database` driver, `same_site = lax`, `secure` unset (local) |

## E. Exact callback URLs

The callback URL is not configured. It is generated by `route('integrations.calendar.callback', $provider)` both when the user is sent to the provider and when the code is exchanged. The value registered with the provider must therefore match the URL the application generates, character for character.

How the root (scheme and host) is chosen, from `AppServiceProvider::configureTrustedOrigin()`:
- **`APP_ENV=local`:** no forced root. The host is the host the browser used. Opening the panel at `http://127.0.0.1:8000` produces a `127.0.0.1` callback, not a `localhost` callback.
- **Any other environment:** `URL::forceRootUrl(APP_URL)` sets the host. The scheme still follows the request (`UrlGenerator::formatRoot()`), so behind a TLS-terminating proxy, `TRUSTED_PROXIES` must be set. Otherwise the generated callback is `http://…` and the provider rejects it as a redirect-URI mismatch.

The URLs below were confirmed with `route()` on this machine (`APP_URL=http://localhost:8000`).

**LOCAL GOOGLE CALLBACK:**
`http://localhost:8000/integrations/calendar/google_calendar/callback`

**LOCAL MICROSOFT CALLBACK:**
`http://localhost:8000/integrations/calendar/microsoft_calendar/callback`

These local URLs are valid only when the panel is opened at `http://localhost:8000`. If it is opened at another address, register that address's equivalent, and start and finish the flow on the same host so the session cookie, and with it the OAuth state, survives.

**PRODUCTION GOOGLE CALLBACK:**
PRODUCTION URL NOT YET AVAILABLE. Format: `{APP_URL}/integrations/calendar/google_calendar/callback`, where `APP_URL` must be `https://` (production preflight blocker `app_url`).

**PRODUCTION MICROSOFT CALLBACK:**
PRODUCTION URL NOT YET AVAILABLE. Format: `{APP_URL}/integrations/calendar/microsoft_calendar/callback`

The production `APP_URL` is still open: BU-I18 in `docs/production-bring-up-stage-1.md`, and "REQUIRES PRODUCTION ACCESS" in `docs/production-bring-up-stage-2b-production-facts.md`.

The callback is one fixed URL per provider for all tenants. The tenant is carried in the session, not in the URL (see §G). Only one redirect URI per provider per environment needs to be registered.

## F. Required OAuth scopes

**Google** (the `scope` parameter in `GoogleCalendarProvider::authorizationUrl()`):

| Scope | Used for |
|---|---|
| `openid` | OpenID identity |
| `email` | `account_email` from the userinfo endpoint |
| `https://www.googleapis.com/auth/calendar.events` | Create, update and cancel interview events, including Meet links |
| `https://www.googleapis.com/auth/calendar.freebusy` | `busyTimes()` through `/freeBusy` |
| `https://www.googleapis.com/auth/calendar.readonly` | `getEvent()` and the Integrations "Test" call (`/users/me/calendarList`) |

The request also sets `access_type=offline` and `prompt=consent`, so Google returns a refresh token on every consent.

**Microsoft Graph** (delegated, in `authorizationUrl()`, `exchangeCode()` and `refresh()`):

| Permission | Used for |
|---|---|
| `offline_access` | Refresh token |
| `User.Read` | `/me`, the account email |
| `Calendars.ReadWrite` | `/me/events` (create, update, cancel, get) and `/me/calendar/getSchedule` |

No application (app-only) permissions are used.

## G. Tenant/employee ownership model

**Client credentials: A. Global application credentials.**
- One Google OAuth client and one Entra app registration serve the whole platform.
- They are read only from the environment through `config/services.php`. The config comment says: "Credentials only ever come from the environment — never the database or code".
- No tenant-specific credential store exists for calendars. `IntegrationConnection` is SaaS-6 webhooks only.
- No employee-specific client credentials exist.

**OAuth tokens: per employee, owned by the tenant.**

| Property | Finding | Evidence |
|---|---|---|
| Employee-scoped | Yes. One row per employee per provider. | `calendar_connections.employee_id` with unique `(employee_id, provider)`. `complete()` uses `firstOrNew(['employee_id' => $employee->id, 'provider' => …])`. |
| Tenant-scoped | Yes | `CalendarConnection` uses `BelongsToTenant` (`TenantScope` global scope, tenant set on create, `tenant_id` immutable). The table is in `TenantSchema` with `employee_id → employees` as a tenant-internal reference. Composite FK added in `2026_10_04_025116_enforce_tenant_ownership.php`. |
| Encrypted at rest | Yes | `access_token` and `refresh_token` use the `encrypted` cast (`APP_KEY`). Registered in `EncryptedColumns` for key rotation. |
| Hidden | Yes | `#[Hidden(['access_token', 'refresh_token'])]`. `Auditable` skips hidden attributes. |
| Refreshable | Yes | `CalendarConnectionService::ensureFreshToken()` refreshes when within one minute of expiry. On failure it sets status to `error` with a non-secret reason, audits, and the employee must reconnect. |
| Isolated between tenants | Yes, in the code reviewed | Queries without a tenant are refused (`TenantScope`). Queued jobs carry `tenant_id` (`TenantQueueGuard`). Covered by `tests/Feature/Tenancy/CrossTenantPublicSurfacesTest.php`. |

Who may connect: a user with `calendar.connect` (seeded for `vp_hr`, `manager`, `assistant_manager` and `recruiter`) **and** a linked employee record. `integrations.manage` users can see and disconnect every connection in their tenant. Other users see only their own (`CalendarConnectionResource::getEloquentQuery()`, `CalendarConnectionPolicy`).

## H. Token security model

| Check | Result | Evidence |
|---|---|---|
| Client secrets outside source control | **Yes** | Env only. `.env` is git-ignored and untracked. `.env.example` values are blank. |
| Access tokens encrypted | **Yes** | `encrypted` cast. The test *"a valid callback stores the tokens encrypted…"* asserts the raw column does not contain the plaintext. |
| Refresh tokens encrypted | **Yes** | Same cast and test. |
| Tokens never in URLs, responses or logs | **Yes** (by design) | Controller docblock. Refresh failure logs only `calendar_connection_id` and `provider`. Audit records `provider` and `account_email` only. |
| OAuth state validated | **Yes** | 40-character random state stored in the session under `calendar_oauth_state.{provider}` and removed on first use (`pull`, single use). Compared with `hash_equals`. A mismatch returns 403 "Invalid OAuth state." Tested ("a callback with a forged state is refused"). |
| Callback belongs to the correct tenant | **Yes** | The tenant ID is stored in the session next to the state at connect time. The callback reloads that tenant, requires `canAccessTenant()`, and completes inside `TenantContext::run($tenant, …)`. Tested ("completes only in the tenant it was started from"). |
| Callback belongs to the correct employee | **Yes** | The connection is written for `$request->user()->employee`, the signed-in user of the same session. No employee identifier exists in the URL or state that could be swapped. `calendar.connect` is re-checked in `complete()`. |
| Cross-tenant access to a connection | **No path found in the code reviewed** | Tenant global scope, composite FKs, tenant-carrying jobs, a per-employee Filament query and the policy. Tenancy architecture and cross-tenant tests cover the callback. |
| Session cookie survives the provider redirect | **Yes** with current settings | `same_site=lax` is sent on the top-level GET back from Google and Microsoft. Microsoft uses `response_mode=query` (GET), not `form_post`. |
| Rate limiting | **Yes** | `calendar-oauth`: 20 requests per minute per staff user. |

## I. Missing configuration

| # | Missing item | Effect |
|---|---|---|
| 1 | `GOOGLE_CALENDAR_CLIENT_ID` | Google is not configured. |
| 2 | `GOOGLE_CALENDAR_CLIENT_SECRET` | Google is not configured. |
| 3 | `MICROSOFT_GRAPH_CLIENT_ID` | Microsoft is not configured. |
| 4 | `MICROSOFT_GRAPH_CLIENT_SECRET` | Microsoft is not configured. |
| 5 | A Google Cloud OAuth Web client with the §E redirect URI and the §F scopes | Needed to obtain items 1 and 2. |
| 6 | A Microsoft Entra app registration (Web platform) with the §E redirect URI and the §F delegated permissions | Needed to obtain items 3 and 4. |
| 7 | **Production only:** an `https://` `APP_URL` and correct `TRUSTED_PROXIES` | Without them, no stable production callback URL exists. **PRODUCTION URL NOT YET AVAILABLE.** |

`MICROSOFT_GRAPH_TENANT` is optional (`common` by default). It is an owner decision (see J.2, step 2).

Points to know once credentials are added:
- **Presence is all that `isConfigured()` checks.** A wrong client ID or an unregistered redirect URI fails at the provider's page (`invalid_client` or `redirect_uri_mismatch`). A wrong secret fails after consent with "The calendar could not be connected. Please try again." and a reported exception.
- **The Integrations page "Test" button** (`testConnection()`) reports "Credentials are set, but no employee has connected…" until at least one employee has connected.
- **Long-running queue workers keep their old config.** Calendar sync runs in `SyncInterviewCalendarJob` on the `integrations` queue (`queue-background` worker), so workers must be restarted after the variables are added.
- **Documentation drift (no effect):** `docs/phase-5-communication-distribution.md` §4 still shows the pre-SaaS-1 connect path `/integrations/calendar/{provider}/connect`. The current path includes `{tenant}` (`docs/saas-1-tenant-foundation.md` line 202). The callback path is unchanged.

## J. Exact steps required to configure it

These steps are for the administrator. They were **not** performed in this diagnostic. Put secrets only into `.env` (local) or the production secret source, never into git.

### J.1 Google Calendar

1. In Google Cloud Console, create or select a project and **enable the Google Calendar API**.
2. **Configure the OAuth consent screen:**
   - Choose the user type: *External* for a SaaS used by other organisations' Google accounts, or *Internal* only if every user is in your own Workspace.
   - Add the §F scopes.
   - While the app is in *Testing*, add the accounts that will connect as test users.
   - Provider policy to verify in the console: apps in *Testing* with *External* users get refresh tokens that expire after 7 days. Production use of these calendar scopes requires Google's app verification.
3. Under **Credentials, Create credentials, OAuth client ID**, choose application type **Web application**.
4. Under **Authorized redirect URIs**, add exactly:
   - Local: `http://localhost:8000/integrations/calendar/google_calendar/callback`
   - Production: `{APP_URL}/integrations/calendar/google_calendar/callback`. PRODUCTION URL NOT YET AVAILABLE.
5. Copy the client ID and secret into the environment:
   ```
   GOOGLE_CALENDAR_CLIENT_ID=<client id>
   GOOGLE_CALENDAR_CLIENT_SECRET=<client secret>
   ```

### J.2 Microsoft 365

1. In Microsoft Entra admin center, go to **App registrations, New registration**.
2. **Choose the supported account types** to match `MICROSOFT_GRAPH_TENANT`:
   - `common` (the current default): accounts in any organisational directory **and** personal Microsoft accounts.
   - `organizations`: any organisational directory only (work or school accounts).
   - A directory GUID: single tenant (your organisation only). This does not fit a multi-tenant SaaS.

   This is an owner decision. The adapter creates Teams meetings (`teamsForBusiness`) and calls `getSchedule`, which target work or school accounts.
3. Under **Redirect URI**, set the platform to **Web** and add exactly:
   - Local: `http://localhost:8000/integrations/calendar/microsoft_calendar/callback`
   - Production: `{APP_URL}/integrations/calendar/microsoft_calendar/callback`. PRODUCTION URL NOT YET AVAILABLE.
4. Under **API permissions, Microsoft Graph, Delegated**, add `offline_access`, `User.Read` and `Calendars.ReadWrite`. Customer directories that restrict user consent will need their admin to consent.
5. Under **Certificates & secrets, New client secret**, create a secret. Copy its **Value** (not its Secret ID) and record its expiry date for rotation (`docs/runbooks/app-key-and-secret-rotation.md`).
6. Copy the IDs into the environment:
   ```
   MICROSOFT_GRAPH_CLIENT_ID=<Application (client) ID>
   MICROSOFT_GRAPH_CLIENT_SECRET=<client secret Value>
   MICROSOFT_GRAPH_TENANT=common   # or organizations / directory GUID, per step 2
   ```

### J.3 Apply and verify

1. If config was cached, run `php artisan config:clear`. Locally it is not cached. In production, the release's config cache must be rebuilt.
2. Restart the app and the queue workers (in Docker, the `queue-background` worker that runs `integrations`).
3. Open the panel at the **same host as the registered redirect URI**, for example `http://localhost:8000/admin/main/calendar-connections`.
4. Click **Connect Google Calendar**. You should see Google's consent screen, then return with "Calendar connected." and a row with status `active`. Repeat with **Connect Microsoft 365**.
5. Optional: on **Administration, Integrations**, run "Test" for each calendar provider.
6. Production prerequisites: the `https://` `APP_URL` is decided and set, `TRUSTED_PROXIES` is set, `SESSION_SECURE_COOKIE=true`, and the production redirect URIs from §E are registered in both consoles.
