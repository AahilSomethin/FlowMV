# Phase 9B — Telegram acceptance and live-test readiness

Date: 28 October 2026. This report distinguishes implementation, simulated verification, browser evidence and live-provider evidence.

## Verdict

**Implemented:** a real, fixed-host Telegram Bot API adapter and a website configuration flow, with encrypted credentials, approved chats, confirmed identity linking, version-pinned triggers and durable delivery.

**Simulated and verified:** automated and actual-browser intake → linked member → pinned workflow → Manager approval → Telegram adapter notification, plus browser authorization, disable/rotation/revoke and mobile checks. Every provider HTTP request in these checks is fake and stray requests are forbidden.

**Live-provider verified:** none. The user has a bot prepared but has not entered its token into FlowMV. No public HTTPS webhook endpoint was supplied.

**Blocked:** live inbound/outbound Telegram verification. Do not call Telegram operational until the live checklist below passes. Existing Phase 8.5 deployment/security gates still apply.

## Completed functionality

- Connections → Telegram offers provider approval, personal/organization ownership, secure Connect, Test, enable/disable, token rotation, revoke, bot approval, chat discovery/approval, workflow grants, identity linking/confirmation, trigger pause/resume and safe delivery/audit history.
- Connect validates the supplied token using Telegram getMe. This establishes token control and bot identity, not the human creator's legal ownership. Saved tokens are never returned. Test also checks the registered webhook URL and reported webhook errors when configured.
- The React Flow palette includes Telegram Trigger, Telegram Filter, Telegram Send Message and Telegram Notification. Choosing Telegram Trigger replaces Start as an undoable draft change and retains its outgoing edge.
- Trigger input mapping is explicit. Filter conditions support command, text or chat type with equals/contains/starts-with; no user regex or code.
- Message templates allow request UUID/status, approval decision and explicitly permitted non-file request answers. Credentials and arbitrary variables are rejected. Messages are plain text with link previews disabled.
- Publication validates grants and creates a subscription pinned to that immutable version. Republishing pauses earlier subscriptions rather than changing their snapshots. Explicit resume of a previous pinned subscription requires admin authorization.
- Verified events are minimized and encrypted. A durable unique connection/update-ID key and event/subscription activation key prevent duplicate internal creation. Request creation, activation completion and queue insertion commit transactionally.
- Workers recheck organization membership, linked identity, active admin authorizer, connection status, credential generation, workflow grant, version/node and destination. A Telegram message never approves an internal task. Managers approve through the existing FlowMV session API.
- Disable/revoke pauses subscriptions. Revoke deletes the local token and grants. Rotation cancels pending deliveries, pauses triggers and requires new bot approval and webhook setup; pending actions cannot silently adopt a new token.
- Encryption-key rotation also re-encrypts queued message bodies and minimized inbound events. Outstanding OAuth contexts retain the foundation's existing short-lifetime key handling.

## Reliability and limits

The database outbox has bounded attempts, availability times, claim ownership and a 120-second lease. The job timeout is 30 seconds and the HTTP timeout is 8 seconds, with a 3-second connection timeout. Configure queue retry_after above the job timeout and below the lease.

Telegram 429 responses honor the bounded retry_after value. Transient provider failures use bounded backoff; maximum five outbound attempts. Shared cache throttles conservatively limit each bot and chat. Run integrations:recover-telegram every minute alongside the existing delay/notification recovery schedules.

A transport timeout, malformed send acknowledgement or interrupted worker becomes **uncertain**. It is not automatically resent. An admin must acknowledge possible duplication before a manual retry; the attempt budget still applies. Explicit provider failures may be retried, and Telegram offers no sendMessage idempotency key: external messaging is **not exactly once**. Internal workflow creation and decisions remain transactional and deduplicated.

A connection lock serializes revocation against an attempt. After revocation commits, future attempts fail authorization. A message already accepted by Telegram cannot be recalled by FlowMV revocation. Invalidate the bot token through BotFather when provider-side invalidation is required.

Invalid/expired/unconfirmed identity links cannot activate requests. Permission-blocked activations are retained for audit and do not automatically revive after permissions change; send a new authorized message. File/media/channel/business-message ingestion and arbitrary external actor privileges are outside this release.

## Security findings and protections

- Telegram webhooks use the supported X-Telegram-Bot-Api-Secret-Token header over HTTPS. This is a configured shared secret, not a Telegram per-message digital signature.
- A bot has at most one active FlowMV binding across organizations, preventing one tenant from replacing another tenant's webhook.
- Linking requires a ten-minute one-time command sent privately by the Telegram account, followed by authenticated FlowMV confirmation. Telegram IDs cannot be claimed by typing an arbitrary identity into the website.
- Unknown senders/chats cannot create requests. Bot senders, unsupported update types, oversized messages and events outside the allowed date window are ignored.
- A private HTTP factory avoids application-wide HTTP request events/middleware that could capture Telegram's token-bearing API URL. Redirects and debug output are disabled; TLS verification stays enabled.
- Raw webhook bodies, secret headers, credential bodies and linking/simulation inputs are redacted before ordinary request instrumentation. Provider descriptions and raw failures are not included in responses or audit logs.
- Integration and workflow audit events contain safe identifiers, statuses and error codes, not tokens or private message text. Explicitly mapped submission content follows the existing permission-scoped request/snapshot contract.
- Generic mock connection routes cannot mutate Telegram credentials. Identity unlinking is constrained by organization and authenticated owner.
- Existing V1/V2 and prior V3 action definitions remain supported. Published definitions, hashes and historical events are not rewritten.
- No unrestricted HTTP node, user-provided API host, arbitrary code, production token fixture, hosted reset, commit or push was introduced.

## Regression ledger

Final checks are run sequentially to limit RAM. Failed intermediate runs are not passes.

| Check | Executed result |
| --- | --- |
| Laravel | 129 passed; 9 PostgreSQL-only skips; 1,853 assertions; final rerun after the UTC correction |
| Guarded PostgreSQL | 9 passed; 103 assertions, including simultaneous Telegram receipt, activation and delivery |
| Telegram automated security/reliability coverage | 19 tests, 456 assertions passed; provider transport is fake; includes UTC audit timestamps |
| Vitest | 53 passed across 12 files, including six Telegram UI tests and dialog keyboard focus restoration |
| ESLint | Passed in the final sequential run, including keyboard focus restoration |
| TypeScript | Passed in the final sequential run |
| Production build | Passed in the final sequential run; 24/24 pages, including /connections/telegram, with one build worker |
| Browser acceptance | Actual isolated website flow: connected synthetic bot, approved chat, linked identity, published version 1, created request, Manager approved, simulated outbound action sent; details below |
| Live Telegram | Blocked: token has not been entered and public HTTPS endpoint is unavailable |

The preliminary Telegram end-to-end/retry/security suite and foundation tests passed together (36 tests, 595 assertions). Tests cover forged secrets, unknown chats, cross-tenant binding, expired/revoked links, duplicate events, stale membership/grants/approval, token/key rotation, Retry-After, attempt limits, ambiguous timeouts, request redaction and non-QA simulation exclusion.

An intermediate TypeScript run rejected a Testing Library option in a new test. The selector was corrected; the affected UI tests, TypeScript and production build then passed. The final affected UI run passed six tests. Vitest emits an existing non-failing warning about its TypeScript configuration's module format. There are no unresolved automated check failures.

## Actual browser evidence

The browser used synthetic Admin and Manager accounts created and verified through the website and a local mail catcher. The Manager joined **QA9B Telegram Acceptance** through the normal invitation flow. No API shortcuts created the workflow or recorded its approval.

| Browser check | Evidence |
| --- | --- |
| Connect and safe errors | Invalid synthetic token produced the sanitized schema error; generated synthetic credential connected **QA9B Review Bot**; token field disappeared after saving |
| Approval and identity | Admin approved provider, bot and **QA9B Review Inbox** (private chat 777); one-time private command led to pending identity 777, then explicit authenticated confirmation |
| Test and webhook setup | Configure webhook and Test succeeded through the guarded simulated Bot API; this does not verify a public receiver or real Telegram |
| Builder and persistence | Four Telegram types visible in palette; Trigger, Filter and Notification configured through inspectors; mapped Incoming request from Message text; Manager-only approval; saved and reloaded template and destination |
| Publication | Validation passed; immutable version 1 published; Connections displayed its active pinned subscription |
| Inbound activation | `/request QA9B browser acceptance` created request **af3d3cdd-ef2a-4722-a909-a704d43dc20f**, version 1, awaiting Manager review |
| Approval and delivery | Separate Manager session approved the task; request reached Completed; activity showed activation, filter, decision, queued action and delivered event; the worker used the simulated provider |
| UTC timestamps | Integration audit now serializes explicit ISO 8601 UTC offsets. Browser shows the original delivery at **09:14:20 Asia/Tashkent**, consistent with the request timeline's **09:14** display; no historical rows were rewritten |
| Disable/enable and rotation | Disable paused version 1 and rejected an incoming event. Enable left the trigger paused. Synthetic token rotation cleared approval/webhook setup and kept the trigger paused; explicit approval, webhook setup and resume were required |
| Unknown chats and identities | Browser submitted a message to unapproved group -999, then an unlinked sender 888 in the approved synthetic group. Neither created another authorized event, request or outbound action; safe database status/count reads confirmed the original single completed request and single sent attempt |
| Revoke and stale page | Revoked the synthetic bot through the website. A second, stale page still offering Submit simulated event was rejected with **Integration permission denied**. Safe database inspection confirmed zero remaining secrets/grants, paused subscription, original completed request and published V3 version 1 preserved |
| Themes and layout | Desktop light/dark inspected; no horizontal overflow at the actual 1265px content viewport |
| Mobile | Verified actual **390×844** viewport, mobile sidebar navigation, stacked Connections and request activity, light/dark themes, wrapping controls and no horizontal overflow. Earlier viewport attempts failed before the browser was restored; these were superseded by measured mobile browser checks |
| Accessibility basics | Labeled credential/step-up fields, visible focus treatment, keyboard operation and Escape dismissal checked. Final built UI returned focus to Withdraw Telegram approval without submitting a change; six UI tests include opener-focus restoration |

Workflow: **9cdd4475-64da-484d-99c0-2f9d881b9b8d**. Connection: **63fd8117-e704-4454-811c-f1fe8471c06b**. The request page was refreshed after the asynchronous worker completed to inspect the final audit state.

Screenshots are local ignored QA artifacts in `backend/storage/app/phase9b-qa/evidence/`: `request-desktop-light.jpg`, `request-desktop-dark.jpg`, `request-mobile-dark.jpg`, `request-mobile-light.jpg`, `telegram-mobile-utc.jpg`, `telegram-final-revoked-utc.jpg`, `request-final-simulated-acceptance.jpg` and `stale-revoked-event-rejected.jpg`. Their Telegram delivery labels reflect simulated provider acknowledgements, not live Telegram receipt. The Telegram breadcrumb originally said Workflow details; it was corrected to Telegram. Keyboard QA also found that dismissing a controlled sensitive-operation dialog lost focus; focus now returns to the opener or Refresh, with a dedicated UI regression test. Final Pint and Git whitespace checks passed.

The interruption stopped local QA processes and expired the browser login. The existing PostgreSQL cluster was restarted in place, without initialization or resets. The original Admin/Manager accounts, workflow and completed request remain. With the user's explicit approval, one additional synthetic **QA9B Browser Reviewer** admin was provisioned only in the verified loopback QA database to finish lifecycle checks; this fixture provisioning is separate from the earlier website-only end-to-end setup. Its test credential file is inside the ignored QA directory and contains no production secret. The QA bot is intentionally left **revoked** after negative acceptance; reconnect a synthetic bot and reapprove/relink/grant before another simulated run.

## Browser QA environment

The isolated browser environment uses the newly created local PostgreSQL database **flowmv_phase9b_qa** at 127.0.0.1:55485. Existing flowmv_phase85_qa and all earlier QA records are preserved. Only the separately guarded flowmv_phase85_test is disposable for PostgreSQL tests.

- Frontend: http://localhost:3005/connections/telegram
- API: http://localhost:8005
- Local mail catcher: http://127.0.0.1:18026; SMTP 127.0.0.1:11026, example.com recipients only, no relay.
- Ignored configuration: backend/.env.phase9b. Ignored evidence/process logs: backend/storage/app/phase9b-qa.
- The simulated transport refuses any environment except phase9b and verifies the actual loopback database name/address. It accepts only synthetic QA-marked credentials, prevents stray HTTP requests and encrypts its short-lived simulated webhook state.
- The website visibly labels simulation. Its local event tool runs the same secret verification, identity, subscription and activation services. This is not evidence of a public HTTPS delivery from Telegram.
- Simulation controls cannot be enabled in production by a request header or query string. They require the guarded server environment. Never enter a real token into this QA instance.

## Migrations and deployment

The additive, forward-only migration is:

backend/database/migrations/2026_10_28_025512_add_telegram_integration.php

It registers Telegram and adds bot metadata, destinations, identity links, pinned subscriptions, encrypted inbound events, activation work and outbound work tables. down() refuses rollback. Existing published rows are untouched.

Applied: isolated Phase 9B browser database and guarded disposable test databases. **Pending: ordinary development/hosted target migration and restricted runtime-role rollout.** No hosted schema, production credential or ownership change was performed.

Review and apply backend/database/integration-runtime-permissions.sql after the migrations, using the object owner/migrator. Preserve the existing immutable audit/version grants. Production workers must use a shared cache, supervised database queue and scheduler. Keep backup/restore and separate key escrow verified.

## Exact live setup

The ordinary development URL is **http://localhost:3000/connections/telegram** when using this repository's standard dev scripts. The isolated localhost:3005 site is simulation-only and must not receive a real token.

The live runtime needs these settings (the key placeholder is a vault encryption key, **not** a bot token):

```dotenv
INTEGRATION_TELEGRAM_ENABLED=true
INTEGRATION_TELEGRAM_SIMULATED=false
INTEGRATION_TELEGRAM_WEBHOOK_ORIGIN=https://your-public-backend.example
INTEGRATION_ACTIVE_KEY=v1
# Supply through the secret manager: INTEGRATION_KEYS={"v1":"<base64 independent 32-byte key>"}
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=90
CACHE_STORE=database
SESSION_DRIVER=database
```

Use the deployment's real HTTPS frontend/API origins in APP_URL, FRONTEND_URL, SANCTUM_STATEFUL_DOMAINS and the frontend NEXT_PUBLIC_API_URL. Rebuild the frontend when changing NEXT_PUBLIC_API_URL. FLOWMV_SESSION_COOKIE, if overridden, must equal Laravel's SESSION_COOKIE. HTTPS deployments require secure cookies; retain the existing session encryption/expiration and CSRF settings. The standard local defaults are frontend localhost:3000, API localhost:8000 and cookie flowmv-session. A public HTTPS tunnel may route the webhook origin to that local API for a controlled live test; a localhost-only receiver cannot receive Telegram events.

1. Deploy the code and run forward migrations with the authorized migrator against the intended database after backup review. Do not reset the database. Apply the restricted runtime grants.
2. Provision the existing independent INTEGRATION_KEYS key ring and INTEGRATION_ACTIVE_KEY through the secret manager. Never reuse APP_KEY. Never put a bot token in an environment file.
3. Set INTEGRATION_TELEGRAM_ENABLED=true and INTEGRATION_TELEGRAM_SIMULATED=false. Set INTEGRATION_TELEGRAM_WEBHOOK_ORIGIN to the public **HTTPS origin of the backend**, without a path, query, credentials or fragment. Keep FRONTEND_URL, APP_URL, Sanctum stateful origins and secure session-cookie settings consistent with the deployed frontend/backend.
4. The public receiver is that origin followed by /api/v1/integration-webhooks/telegram/{connection UUID}. A loopback-only API cannot receive Telegram's webhooks. Configure DNS/TLS and proxy routing without body, secret-header or bot-token URL logging. The webhook secret is generated server-side; do not invent or paste one.
5. Rebuild cached Laravel configuration and restart web/queue workers. Run a supervised queue worker and Laravel's scheduler. The recovery command runs every minute.
6. In FlowMV, sign in as an admin and open Connections → Telegram. Approve Telegram for the workspace. If members need personal credentials, enable the existing personal-connection policy in Connections.
7. Select ownership and connection name, click Connect Bot, and enter the existing bot token in the password-style website field. Confirm the FlowMV password. Connect calls getMe; the saved token is never displayed afterward.
8. An admin approves the connection. The owner/admin then clicks Configure webhook. Click Test to validate token control and the configured receiver. For groups, add the bot with the minimum rights needed; FlowMV verifies getChat and the bot's membership before chat approval. Telegram privacy mode may restrict which group messages the bot receives.
9. Each sender clicks Link my account, sends the displayed one-time command privately to the bot, refreshes FlowMV, checks the received Telegram account ID and clicks Confirm account.
10. Send a harmless test message to discover a chat. An admin reviews and approves that chat, then grants the intended workflow its separate receive/send/notification permissions. Do not approve unrelated chats.
11. Build Telegram Trigger → Telegram Filter (/request) → Manager Approval → Telegram Notification → End, with a rejected/unmatched End path. Map only intended request fields and select only approved destinations. Save, validate and publish as an admin.
12. Send /request with harmless synthetic content from the linked account in the approved chat. Verify exactly one request pinned to the subscription's version, Manager-only approval, queued delivery and safe audit events. Approve through FlowMV, then verify the actual Telegram chat receives the notification and FlowMV records provider acknowledgement.
13. Replay a delivery/event and disable/revoke the connection in a controlled test. Verify no new unauthorized request/action starts. Retain actual provider evidence before changing this report's live status.

From the deployed backend directory, the operator commands are `php artisan migrate --force`, `php artisan config:cache`, `php artisan queue:restart`, supervised `php artisan queue:work --sleep=1 --tries=1 --timeout=30`, and a system scheduler invoking `php artisan schedule:run` every minute. Use the authorized deployment configuration and migrator for these commands; **do not** run them against an unidentified database or use reset/fresh/rollback. No migration or production deployment command in this paragraph was executed against a hosted target during acceptance.

Before declaring Telegram operational, complete the pending target migration/runtime grants, configure the non-simulated runtime and HTTPS ingress, enter the bot token only in the website, and retain evidence of both an actual incoming provider update and a message received in the approved Telegram chat after Manager approval. No other provider implementation is needed for this acceptance gate.

Telegram's documented references: [getMe and Bot API methods](https://core.telegram.org/bots/api#getme), [setWebhook and its secret header](https://core.telegram.org/bots/api#setwebhook), [sendMessage](https://core.telegram.org/bots/api#sendmessage), [rate-limit response parameters](https://core.telegram.org/bots/api#responseparameters). The installed Next.js .md guides for routing and client/server components were also consulted.
