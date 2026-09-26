# Phase 5 — Communication & Distribution

Developer reference for the Phase 5 modules:

- the Communication Center, templates and candidate preferences;
- email, WhatsApp and SMS providers, with delivery tracking and webhooks;
- calendar and video-meeting integration;
- job distribution and the public career site;
- recruitment campaigns;
- event-driven candidate communications.

Short rules for agents and teammates live in `.ai/rules/` (`communication.md`, `distribution.md`,
`webhooks.md`, `app-services.md`). This document explains how the pieces fit together.

---

## 1. Design principles

- **One path per concern.**
  - Candidate messages go through `CommunicationService`.
  - Calendar and video work goes through `CalendarSyncService`, triggered by interview events.
  - Job publishing goes through `JobDistributionService`.
  - Online applications go through `CareerApplicationService`, into the existing pipeline.
- **Providers are adapters.** Only classes under `*/Providers/` or `*/Connectors/` talk to
  external APIs. They return normalized DTOs (`DeliveryResult`, `CalendarEventResult`,
  `DistributionResult`, `IntegrationTestResult`) and never throw provider-specific exceptions
  upwards.
- **Integration honesty.** `IntegrationRegistry` reports three separate facts for each
  integration:

  | Fact | Meaning |
  | --- | --- |
  | *implemented* | The adapter code exists. |
  | *configured* | Credentials are present in the environment. |
  | *operational* | The **last explicit connection test** stored in `integration_statuses` succeeded. |

  Nothing is shown as operational until it has been tested. A job board without API access
  records a Failed "not configured" distribution, never a fake success.
- **Credentials.**
  - API credentials live only in the environment, read through `config/services.php`.
  - Per-user OAuth tokens are stored in `calendar_connections` with the `encrypted` cast and
    hidden from serialization.
  - Nothing is stored on `interviews` except `meeting_provider` and `external_meeting_id`.
- **Events after commit.** All new events implement `ShouldDispatchAfterCommit`. Listeners are
  auto-discovered, so do not register them manually as well.
- **Idempotency.** Every automatic message carries a unique `idempotency_key`. The send job
  claims each message under a row lock. Webhook events are de-duplicated by provider and event ID.

## 2. Database tables (all additive)

| Table / column | Purpose |
| --- | --- |
| `communication_templates`, `communication_template_versions` | Templates per key + channel; versions are immutable snapshots |
| `candidate_communication_preferences` | One row per candidate + channel (`opted_in`, `opted_out`, `unknown`), audited |
| `candidate_communications` | Every outbound message: channel, rendered content, status, provider message ID, `idempotency_key` (unique), `public_id` (ULID) |
| `communication_webhook_events` | Received provider callbacks (unique provider + event ID) for replay protection |
| `integration_statuses` | Last connection-test result per integration |
| `calendar_connections` | Per-user OAuth connection (encrypted access and refresh tokens) |
| `interview_calendar_events` | External event per interview + connection (provider event ID, join URL) |
| `interviews.meeting_provider`, `interviews.external_meeting_id` | Chosen video provider and its meeting ID |
| `job_postings` | One public posting per requisition (`public_slug`, content, status) |
| `job_distributions` | One row per posting + channel with status, external ID/URL and last error |
| `candidate_applications.origin_channel`, `.job_posting_id`, `.campaign_id` | Where an application came from |
| `recruitment_campaigns` (+ `_requisitions`, `_sources` pivots) | Campaigns with budget, tracking code and linked requisitions and sources |
| `recruitment_costs.campaign_id` | Campaign spend reuses the existing cost ledger |

The portal's old JSON `communication_preferences` column was backfilled into the new table and is
deprecated.

## 3. Communication

### Flow

```
CommunicationService::send() / sendAutomatic()
  ├─ CommunicationPreferenceService::blockedReason()   (opt-out; WhatsApp needs explicit opt-in)
  ├─ TemplateRenderer::render()                         (whitelisted {{variables}} only)
  ├─ candidate_communications row (Queued, idempotency_key)
  └─ SendCommunicationJob (queue "communications", tries 5, backoff 30s/2m/10m/30m)
        └─ CommunicationProviderManager → provider adapter → DeliveryResult
```

- **Retryable vs permanent failures.**
  - A *retryable* failure (network error, 5xx, 429) throws, so the queue retries it.
  - A *permanent* failure marks the message Failed at once.
  - `failed()` marks the message Failed once all attempts are used up.
- **No duplicate sends after a crash.** If the job finds a message stuck in Sending, it marks it
  Failed instead of resending it.

### Templates

- Templates use `{{variable}}` placeholders from `TemplateRenderer::VARIABLES`: candidate,
  requisition, application, interview, recruiter, offer, joining, company and link values.
- Unknown variables are rejected when the template is saved. There is no Blade or PHP evaluation,
  and values are escaped when rendered as HTML email.
- Each save creates a new immutable version. A sent message stores the exact rendered subject and
  body.
- WhatsApp templates also carry the approved Meta template name. Its body variables become
  ordered parameters.

### Preferences

`CommunicationPreferenceService` is the single source of truth, used by the admin editor, the
portal profile page and the providers.

- Email and SMS are allowed unless the candidate has opted out.
- WhatsApp requires an explicit opt-in.
- A STOP reply received by a webhook opts the candidate out automatically.

### Providers

| Channel | Adapter | Credentials |
| --- | --- | --- |
| Email | `LaravelMailEmailProvider` (uses the app mailer) | `MAIL_*` |
| WhatsApp | `WhatsAppCloudProvider` (Meta Cloud API, template messages) | `WHATSAPP_CLOUD_*` |
| SMS | `TwilioSmsProvider` | `TWILIO_*` |

The email adapter reports `deliversExternally = false` for the `log` and `array` mailers, so its
connection test fails honestly in local environments.

### Delivery tracking and webhooks

| Method | URL | Purpose |
| --- | --- | --- |
| `POST` | `/webhooks/communications/{provider}` | Delivery callbacks; `{provider}` is `whatsapp_cloud` or `twilio` |
| `GET` | `/webhooks/communications/{provider}` | WhatsApp verify handshake (`WHATSAPP_CLOUD_VERIFY_TOKEN`) |

- Signatures are verified before any processing: `X-Hub-Signature-256` with the app secret for
  WhatsApp, `X-Twilio-Signature` for Twilio.
- `webhooks/*` is CSRF-exempt in `bootstrap/app.php` and rate-limited by the `webhooks` limiter.
- `DeliveryStatusService` ignores replays and only moves a status forward
  (`CommunicationStatus::rank()`): a late "delivered" never overwrites "read".

## 4. Calendar and video

- **OAuth.** Users connect a calendar at `/integrations/calendar/{google_calendar|microsoft_calendar}/connect`.
  - The callback URL is `/integrations/calendar/{provider}/callback`; register it with the
    provider.
  - The OAuth state is checked against the session.
  - Tokens are refreshed automatically before they expire. Connect and disconnect are audited.
- **Sync.** `InterviewService` dispatches `InterviewScheduled`, `InterviewRescheduled` and
  `InterviewCancelled`. The `SyncInterviewCalendar` listener queues `SyncInterviewCalendarJob` on
  the `integrations` queue, which creates, updates or cancels the external event on the
  interviewer's connection.
- **Candidate attendee.** The candidate is added as an attendee only if email communication is
  allowed for them.
- **Meetings.**
  - Google Meet links come from Google `conferenceData`.
  - Teams links come from Microsoft `isOnlineMeeting`.
  - Zoom meetings are created by `ZoomMeetingProvider` (server-to-server OAuth).
  - The join URL is written back to the interview.
- **Free/busy.** `InterviewSchedulingService::createSlots()` skips slots that clash with the
  interviewer's connected calendar.

## 5. Job distribution and career site

- **Publishing rules.** `JobDistributionService::publish()`:
  - requires an **Open** (approved) requisition;
  - refuses a channel that is already live;
  - records one `job_distributions` row per channel, with its honest result.
- **Channels.**

  | Channel | Status |
  | --- | --- |
  | `career_site` | Internal, operational |
  | `xml_feed` | Internal, operational |
  | `linkedin`, `naukri`, `indeed`, `apna`, `workindia` | `UnavailableJobBoardConnector` extension points, until a partner API contract exists |

- **Public career site.**

  | URL | Page |
  | --- | --- |
  | `/careers` | Posting list |
  | `/careers/{slug}` | Posting detail |
  | `/careers/{slug}/apply` | Application form |
  | `/careers/feed.xml` | XML job feed |

  The application form uses a honeypot, requires privacy consent, and accepts a PDF/DOC/DOCX
  résumé up to 5 MB. It is throttled by the `career-apply` limiter.
- **Online applications.** `CareerApplicationService`:
  - reuses an existing candidate when the email or mobile matches exactly;
  - blocks duplicate applications to the same requisition;
  - stores the résumé and consent;
  - writes a timeline entry;
  - fires `CandidateAppliedOnline`.
- **Attribution.** `?campaign=CODE` and `utm_source` are stored on the application
  (`campaign_id`, `origin_channel`).
- **Clean-up.** `jobs:sync-distributions` (daily at 01:00) closes postings whose requisition is no
  longer Open or whose closing date has passed.

## 6. Campaigns

A `RecruitmentCampaign` links requisitions and sources, and has a budget and a tracking code.

- `RecruitmentAnalyticsService::campaignAnalytics()` reports:
  - applications, shortlisted, interviews, offers and joined;
  - spend, from `recruitment_costs.campaign_id`;
  - cost per application and cost per hire.
- A metric that cannot be computed is returned as `null` and is never estimated.

## 7. Event-driven communications

The `SendCandidateCommunications` listener runs queued on `communications`.

| Event | Template key | Idempotency |
| --- | --- | --- |
| `InterviewScheduled` | `interview_scheduled` | Per interview |
| `InterviewRescheduled` | `interview_rescheduled` | Per interview + new start time |
| `InterviewCancelled` | `interview_cancelled` | Per interview |
| `OfferReleased` | `offer_released` | Per offer |
| `CandidateAppliedOnline` | `application_received` (also alerts the recruiter) | Per application |

`communications:send-reminders` (hourly) sends interview reminders 24 h ahead and joining
reminders 2 days ahead. A template must be **Active** before it is used; seeded SMS and WhatsApp
templates are Draft until the provider approves them.

## 8. Permissions

| Permission | Grants |
| --- | --- |
| `communications.view` | Communication Center (hierarchy-scoped) |
| `communications.send` | Send message action |
| `communications.templates` | Manage templates |
| `communications.preferences` | Edit candidate preferences |
| `integrations.manage` | Integrations page and connection tests |
| `calendar.connect` | Connect own calendar |
| `jobs.publish` | Job Publishing |
| `campaigns.manage` | Recruitment Campaigns |

Permissions are granted in `RolePermissionSeeder` (`PHASE_5_ROLE_PERMISSIONS`) and backfilled for
existing installs by the `2026_09_26_000001_grant_phase_five_permissions` migration.

Candidates in the portal only ever see messages that were actually sent to them. They never see
internal notes, scores, feedback, audit data or other candidates.

## 9. Operations

### Queue worker

```bash
php artisan queue:work --queue=communications,integrations,default
```

### Scheduled commands (`routes/console.php`)

| Command | Schedule |
| --- | --- |
| `communications:send-reminders` | Hourly |
| `jobs:sync-distributions` | Daily 01:00 |
| `interview-slots:expire` | Hourly |

### Environment variables (leave blank until a real account exists)

```dotenv
COMMUNICATIONS_QUEUE=communications
COMMUNICATIONS_DEFAULT_COUNTRY_CODE=91
WHATSAPP_CLOUD_TOKEN=  WHATSAPP_CLOUD_PHONE_NUMBER_ID=  WHATSAPP_CLOUD_APP_SECRET=  WHATSAPP_CLOUD_VERIFY_TOKEN=
TWILIO_ACCOUNT_SID=  TWILIO_AUTH_TOKEN=  TWILIO_SMS_FROM=
GOOGLE_CALENDAR_CLIENT_ID=  GOOGLE_CALENDAR_CLIENT_SECRET=
MICROSOFT_GRAPH_CLIENT_ID=  MICROSOFT_GRAPH_CLIENT_SECRET=  MICROSOFT_GRAPH_TENANT=common
ZOOM_ACCOUNT_ID=  ZOOM_CLIENT_ID=  ZOOM_CLIENT_SECRET=
```

### Bringing an integration live

1. Set its credentials.
2. Run `php artisan config:clear`.
3. Open **Integrations → Test**.
4. Confirm the status turns *Operational*.
5. For WhatsApp or SMS, activate the provider-approved templates.

## 10. Testing

| Test file | Tests |
| --- | --- |
| `CommunicationServiceTest` | 14 |
| `CommunicationWebhookTest` | 9 |
| `CommunicationCenterUiTest` | 10 |
| `CalendarIntegrationTest` | 15 |
| `JobDistributionTest` | 22 |
| `RecruitmentCampaignTest` | 8 |
| `EventDrivenCommunicationTest` | 8 |
| `CommunicationDistributionAnalyticsTest` | 5 |

All external calls are faked with `Http::fake`, `Mail::fake` and `Queue::fake`; no test touches a
real provider. A real-browser smoke test (Playwright, run outside the project) passed 26 of 26
checks. It covered:

- compose and live preview;
- preferences;
- templates;
- integrations;
- calendar connect without credentials;
- publishing;
- campaigns;
- dark mode and themes;
- career-site apply;
- the portal;
- permission denials.

## 11. Not in Phase 5

The following are deferred to later phases:

- bulk campaign messaging;
- AI-written message drafting beyond the existing copilot tool;
- two-way inbox and conversation threads;
- job-board partner integrations (these need commercial API contracts);
- assessments, background verification and e-signature.
