# Phase 9D — Communication acceptance

Updated: 30 October 2026 (Asia/Tashkent). Implementation and simulated acceptance complete; live-provider verification pending.

## Acceptance status

Slack, interactive Telegram decisions and Twilio SMS are implemented and verified with isolated simulated providers. **No live-provider verification has occurred.** Real Slack installation, Telegram delivery and billable Twilio SMS remain blocked on dedicated credentials, public HTTPS and explicit live testing. Do not describe any provider as live operational.

All work remains uncommitted. No pushes, hosted resets, production credential changes, AI, Phase 10 or Phase 11 implementation occurred. Existing Phase 9B/9C data and published snapshots were preserved. The SMS connection created for this acceptance run is intentionally left revoked after lifecycle testing; its completed request and delivery evidence remain available. Slack and Telegram QA connections remain intact.

## Security and architecture audit

Laravel 13.38.0, Sanctum 4.3.3, PHP 8.4.23; Next.js 16.4.3, React 19.3.0, existing shadcn/ui and Lucide. No dependencies added.

The existing Laravel workflow engine, task eligibility, no-self-approval, row locking, idempotent decisions, immutable submission snapshots, organization RBAC and notification outbox remain authoritative. NodeRegistry V1/V2 contracts are preserved. New nodes and optional Telegram approval configuration stamp V3; execution uses the stored published snapshot. Registry CURRENT remains V2 for prior contracts and AUTHORING is V3. Publishing creates another immutable version and pauses prior external-intake subscriptions; it does not rewrite existing requests or versions.

The existing independent, versioned AES-GCM vault encrypts credentials and private event/outbox payloads with context binding. SMS phone numbers and fingerprint keys also use the vault. APIs, audit events and workflow JSON expose safe references and metadata only. Dedicated HTTP factories avoid application HTTP-event logging and prevent stray requests during tests. Provider errors are reduced to safe codes; request protection removes credentials, OAuth state/code, private body fields, phones and tokens before downstream exception reporting.

Authenticated settings reuse Sanctum sessions, CSRF, current tenant membership, API throttles and password step-up bound to the current user/session for five minutes. Public provider ingress routes use provider verification and separate throttles. Queue workers recheck current membership, provider policy, credential generation, grants, operation, destination and pinned version. Connecting a service never grants every member permission to use it.

### Slack

One-use hashed OAuth state expires after five minutes and binds session, user, organization, app and exact HTTPS redirect. Confidential-client code exchange is supported; S256 PKCE is optional only when explicitly configured in the Slack app's public-client mode. Bot identity is checked with auth.test. No user-token or message-history scopes are requested. One active workspace cannot be assigned to multiple tenants. Reconnect requires the same workspace/ownership, increments generation, clears channel/grant/identity approvals and pauses intake. Refresh rotation is serialized; missing scopes or changed identity fail closed.

Fixed `https://slack.com/api` methods only, verified TLS, redirects off, 3-second connect/8-second total timeout and bounded responses. Commands and app mentions are accepted only after raw-body v0 HMAC verification, a ±300-second timestamp check, app/workspace/channel checks and confirmed two-sided identity linking. Bot loops and unsupported events are ignored. Durable event deduplication precedes asynchronous version-pinned activation. Slack messages never authorize internal approval decisions.

Outboxes have per-instance/node uniqueness, leases, five bounded attempts, rate limits/Retry-After, recovery and current authorization checks. Ambiguous delivery is not automatically resent. Messages are plain text with markdown parsing/unfurls disabled. Local revoke removes access immediately and attempts Slack auth.revoke; confirmed or unconfirmed provider revocation is audited. Retiring an app also requires checking/removing consent in Slack.

The website supports personal/organization ownership subject to policy, OAuth connect/reconnect, workspace/channel approval, exact grants, identity linking, test, disable/enable and revoke. Slack Trigger, Message Filter, Send Message and Notification are configurable in React Flow. The optional review recipe creates an unpublished generic draft; grants and publication are separate explicit actions.

### Interactive Telegram decisions

The existing Approval node optionally selects an approved private chat for a confirmed eligible reviewer. The existing Telegram connector sends generic buttons through a durable encrypted approval outbox. Callback data contains a random opaque 192-bit capability; only its SHA-256 hash is stored in the capability table. It expires after ten minutes and binds the active task, recipient, private chat, message, credential generation and published version.

Verified callbacks are encrypted and deduplicated before queue processing. Workers lock the callback, capability, delivery, task and request, recheck identity/membership/role/no-self-approval/grants/status/version, then invoke the existing DecideWorkflowTask service. The decision snapshot and sibling-capability consumption are atomic. Duplicate, expired, demoted, unlinked, wrong-chat, wrong-message and revoked attempts cannot decide. Safe answerCallbackQuery failures never repeat a decision. Revision opens an authenticated FlowMV form; private revision details are not collected through chat. The Connections UI identifies expired buttons and links to FlowMV.

### Twilio SMS

Organization-owned credentials only; administrators approve provider policy, account, owned SMS sender number, recipient consent, template and exact workflow grant. Personal credentials, arbitrary recipients, messaging-service configuration, incoming SMS triggers, MMS, Unicode and bulk marketing are not implemented.

Fixed `api.twilio.com` and `lookups.twilio.com` endpoints only. Account status and ownership/SMS capability of sender numbers are checked. Basic Lookup validates canonical recipient number/country without requesting paid enrichment fields. Explicit administrator consent expires after 30 days; provider validation does not prove consent or ownership. Phone numbers are encrypted; per-organization keyed fingerprints use independently random encrypted keys. Non-admin catalog responses do not enumerate recipients; builder options require an accessible workflow and its grants. Admin lists show labels and masked last four digits only.

SMS Notification and SMS Send Message store connection/sender/recipient/template UUIDs only. Approved template bodies are immutable at database level. Only request.uuid, request.status, request.link and approval.decision variables are allowed. Rendered text must be one basic GSM segment, at most 160 ASCII characters; request answers, HR fields and documents cannot be interpolated.

Organization limits: 1–10 messages/minute, at most 100/day and 1,000/month, at most 10/recipient/day; initial UI defaults 3/minute, 10/day, 100/month, 3/recipient/day. The policy row serializes quota checks across connections. A conservative budget reservation commits before external HTTP, so failures/uncertainty still consume volume. A retry crossing the UTC day boundary fails closed. Limits are message caps, **not a currency spending guarantee**.

The durable outbox has unique instance/node, exact resource/generation binding, a one-hour expiry, 120-second lease and five attempts maximum. Explicit 429 responses honor bounded Retry-After; timeouts/5xx are uncertain and never automatically resent. Known provider SIDs support at most 20 status reconciliations, five minutes apart. Signed form callbacks use the configured exact HTTPS URL, all scalar parameters and HMAC-SHA1. Account, route, SID and available From/To fields bind the receipt to its encrypted outbox snapshot. Duplicate and out-of-order terminal statuses cannot repeat effects. A signed callback can resolve an uncertain send with an unknown SID only when its exact From/To match a reserved attempted delivery. Classic Twilio signatures have no timestamp; replay is harmless through binding/deduplication/monotonic state, rather than freshness rejection.

## Connection permission matrix

| Operation | Slack personal | Slack organization | Telegram decisions | SMS |
| --- | --- | --- | --- | --- |
| Connect/manage credentials | Owner, if organization policy permits | Admin | Existing owner/admin policy | Admin; organization only |
| Approve provider/account/destination | Admin | Admin | Admin approves private reviewer destination | Admin approves account, owned sender and consented recipient/country |
| Grant use | Owner after admin approvals | Admin | Explicit telegram.approval workflow/destination grant | Admin; exact action + workflow + sender + recipient + template |
| Discover another member's private credentials | Never | No decrypted secrets returned | Never | Never; members cannot enumerate phone recipients |
| Execute queued action | Current owner/membership + exact grant | Current membership + exact grant | Current linked eligible reviewer, task and connection | Current membership/authorizers, grant, consent, country, quota, generation |
| Make an internal decision | Slack message grants none | Slack message grants none | Existing Laravel decision service only | SMS status grants none |
| Sensitive settings | Password step-up + server authorization | Same | Same | Same |

## Migration report

All three migrations are additive and forward-only; down() refuses rollback. Applied only to loopback `flowmv_phase9d_qa` and guarded disposable automated test databases. No hosted deployment was performed.

| Migration | Additions |
| --- | --- |
| 2026_10_30_005240_add_slack_integration.php | Approved-provider catalog entry; slack_installations, slack_oauth_attempts, slack_channels, slack_workflow_grants, slack_identity_links, slack_trigger_subscriptions, slack_inbound_events, slack_trigger_activations, slack_action_outbox (9 tables) |
| 2026_10_30_012749_add_telegram_decision_capabilities.php | telegram_approval_deliveries, telegram_decision_capabilities, telegram_decision_callbacks (3 tables) |
| 2026_10_30_063120_add_sms_integration.php | Twilio provider entry; sms_accounts, sms_policies, sms_senders, sms_recipients, sms_templates, sms_workflow_grants, sms_action_outbox, sms_status_receipts (8 tables) |

Tenant composite foreign keys, active workspace/account uniqueness, event/outbox uniqueness and immutable-template triggers apply. Runtime grants are documented in backend/database/integration-runtime-permissions.sql; they require a separately provisioned restricted role and retain existing audit immutability. Migration owner credentials must not be the production runtime role.

The local Phase 9D QA database was copied non-destructively from inactive Phase 9C. Private document ciphertext was separately copied to storage/app/phase9d-qa/documents using its own storage-root setting; retention/rotation cannot delete source Phase 9C evidence. Existing QA accounts, two completed email requests and old workflow snapshots remain preserved. The existing PostgreSQL data directory was restarted between earlier sessions without initialization/reset.

## Browser acceptance evidence

All provider transports were fake. The user explicitly approved isolated Phase 9D synthetic Slack, Telegram and SMS setup/grants; no real account, message or hosted database was affected. An earlier automatic-review rejection of the initial Slack approval was reported and not bypassed; approval was obtained before proceeding.

| Demonstration | Verified evidence |
| --- | --- |
| Slack v1 | Website connect → workspace/channel approval → separate receive/notification grants → two-sided linked identity → signed command → Manager My Tasks approval → one accepted Slack action. Request 4f6726e4-f30c-4c0a-9b5d-8eb66f57fa3d |
| Telegram v2 | Existing Approval node configured in Studio, explicit telegram.approval grant, private linked Manager 888, signed Slack command → generic Telegram buttons → separate Manager password step-up → simulated verified callback → existing Laravel decision + one snapshot → Slack accepted. Request 716ad5ab-508c-4ed5-ac20-d60a59b80bd8 |
| SMS/multichannel v3 | Website SMS policy, encrypted synthetic credentials, account approval, owned sender, consented recipient, immutable short template and exact grant. Studio added SMS Notification after Slack outcome, validated and published v3. Signed command created request decd86d1-eb57-44f8-aa0d-d68bd9f09489. Telegram buttons were accepted; a callback submitted after their ten-minute expiry was rejected and task stayed open. Separate Manager used the authenticated My Tasks fallback. One decision snapshot, one accepted Slack action, one accepted SMS at attempt 1; bounded fake-provider reconciliation then reported delivered |
| Lifecycle | SMS account verification on Connect and Approve during setup, disable → disabled, enable → enabled, revoke → revoked. Database confirmed zero stored SMS credentials and zero SMS grants, while the delivered action and decision snapshot remain. No previous Slack/Telegram credential was revoked |
| Privacy/session | Manager SMS page exposes no credential/recipient-management controls. Telegram Manager sees only bound decision controls; no token controls. Sign-out and expired session require login; sensitive actions confirm password |
| Responsive/accessibility | 390×844 viewport: member and admin SMS pages, dark theme, no horizontal overflow (375px client/scroll width, 15px scrollbar). Accessible labeled selectors/dialogs; keyboard focus-restoration regressions pass. Light theme also passed with no overflow; Enter opened the policy dialog, Escape closed it, and focus returned to Configure policy after the transition |

Workflow `1c59a8ae-8a2b-4fa5-a411-22bd3964f901` retains immutable versions 1, 2 and 3, all registry V3. The original V1/V2 contracts are separately regression-tested. Version 3 now references the deliberately revoked SMS QA connection; to replay SMS, connect/approve a new synthetic account, grant its exact resources and publish another version. Do not rewrite v3.

Screenshots are ignored local evidence under backend/storage/app/phase9d-qa/: slack-completed-desktop.png, telegram-decision-completed-desktop.png, multichannel-v3-completed.png, sms-member-390-dark.png, sms-admin-390-dark.png sms-revoked-desktop.png, sms-admin-390-light.png final-multichannel-acceptance.png and final-provider-outcomes.png. Full-page screenshot capture was unavailable for some final views; viewport screenshots and the DOM/browser acceptance observations were retained. The completed v3 timeline records queue and provider acceptance separately. SMS history explicitly labels UTC; request timeline displays the same instants in the local timezone (UTC+05:00).

## Final sequential verification

| Order | Check | Actual result |
| --- | --- | --- |
| 1 | Laravel, php vendor/bin/phpunit | 220 total: 207 passed, 13 expected PostgreSQL-only skips; 2,802 assertions; exit 0 |
| 2 | Guarded PostgreSQL, php vendor/bin/phpunit -c phpunit.pgsql.xml | 13 passed, 120 assertions; exit 0 |
| 3 | Vitest | 67 passed across 18 files; exit 0 |
| 4 | TypeScript, tsc --noEmit | Passed; exit 0 |
| 5 | ESLint | Passed; exit 0 |
| 6 | Laravel Pint --dirty --format agent | Passed with formatting fixes |
| 7 | Production build | Passed; compiled in 37.3s, TypeScript passed, 30 static pages generated; exit 0. Separate ignored .next-phase9d-final output, one worker |
| 8 | Final browser pass | Passed on localhost dev UI: completed request + delivery status, lifecycle, member/admin authorization, 390px dark/light layout, keyboard dismissal/focus return. Viewport and theme restored; final request tab retained |

Security coverage includes tenant isolation, private credential boundaries, unauthorized grants, OAuth state/session/replay attacks, signed webhook forgery/replay, destination restrictions, current membership/demotion, no-self-approval, duplicate callbacks, stale capabilities, revoked/generation-changed connections, queued grant changes, bounded retries/ambiguity, quotas, phone/credential leakage and vault rotation. PostgreSQL races prove single Slack refresh/event receipt, one Telegram Approve-vs-Reject internal decision, and concurrent SMS workers sharing one committed daily budget with only one provider attempt. SMS signature verification also matches Twilio's published reference vector. No exactly-once external-message guarantee is claimed.

Tests use in-memory SQLite or a guard-verified loopback database named flowmv_phase85_test. Only that explicitly disposable test target is rebuilt. Windows sandbox PHP file-read and Vitest temporary-file limitations required running verification outside the sandbox; this did not change database targets. Vite emits a non-failing advisory about its future native config loader.

## Operator setup and required environment

Provision independent vault keys via INTEGRATION_KEYS (JSON version → base64 32-byte key) and INTEGRATION_ACTIVE_KEY. Never reuse APP_KEY. Keep keys in a secret manager, outside source code. Add a new key version, switch active version, run `php artisan integrations:rotate-keys`, verify credential/payload/document reads, then retain prior keys for outstanding OAuth state, backups and recovery. Rotation now covers Slack OAuth/event/action, Telegram decision delivery/callback and SMS policy/sender/recipient/action contexts. Never remove old keys before retention/backup requirements are resolved.

Use real HTTPS frontend/API origins, the normal Sanctum stateful-domain/CSRF/cookie configuration, FRONTEND_URL, a supervised database-backed queue worker and Laravel scheduler. Set DB_QUEUE_RETRY_AFTER above worker timeout and provider lease, as in local QA (180 seconds versus 90-second worker timeout and 120-second lease). Scheduled recovery commands are integrations:recover-slack, integrations:recover-telegram and integrations:recover-sms. Deploy forward migrations and runtime grants on an explicitly checked target after backup/review. Do not run migrate:fresh/reset against hosted data.

### Slack website setup

1. Create a dedicated Slack app and bot. Request only commands, app_mentions:read, chat:write, channels:read and groups:read. No history/search/user-token scopes. Configure app_mention, app_uninstalled and tokens_revoked subscriptions; invite the bot only to intended channels.
2. Set exact frontend redirect `https://FRONTEND/integrations/slack-callback`; Events URL `https://API/api/v1/integration-webhooks/slack/events`; slash-command URL `https://API/api/v1/integration-webhooks/slack/commands`. Configure /flowmv_link and commands used by workflow filters.
3. Set SLACK_ENABLED=true, SLACK_SIMULATED=false, SLACK_CLIENT_ID, SLACK_CLIENT_SECRET, SLACK_SIGNING_SECRET, SLACK_APP_ID and SLACK_REDIRECT_URI. SLACK_PKCE_ENABLED defaults false; enable only when the app is explicitly configured for Slack's supported PKCE/public-client mode. Verify app token rotation settings.
4. Sign in to FlowMV → Connections → Slack. Admin approves provider; permitted owner connects through Slack and confirms the FlowMV password. Admin approves installation, discovers/approves exact member channels. Owner/admin grants each workflow's receive/action operation and channel.
5. Link the member's Slack identity with the short-lived /flowmv_link challenge, then confirm the received identity in FlowMV. Configure explicit trigger/filter/message nodes in Studio, check and publish. After disable/reconnect, review grants and republish to resume paused intake.
6. Verify real OAuth, signatures, refresh, actual message receipt and revocation independently. Audit provider.revocation_unconfirmed requires provider-side consent/token review.

### Telegram inline approval setup

1. Set INTEGRATION_TELEGRAM_ENABLED=true, INTEGRATION_TELEGRAM_SIMULATED=false and INTEGRATION_TELEGRAM_WEBHOOK_ORIGIN to the public HTTPS API origin. Keep bot tokens out of environment/source/logs; enter them through Connections → Telegram.
2. User creates a dedicated bot in BotFather and supplies its token in FlowMV's sensitive Connect form. Admin approves provider/connection, configures the webhook through FlowMV, discovers and approves the intended private reviewer chat. The bot must receive a message from that reviewer first.
3. Reviewer signs in, starts identity linking, sends the one-time challenge privately to the bot, returns to FlowMV to inspect and confirm the identity. Admin grants telegram.approval and its exact destination to the workflow.
4. In Studio select the existing Approval node → enable Telegram decision buttons → choose approved bot/private reviewer. Keep ordinary assignee roles and no-self-approval rules. Configure approved/rejected outcomes and revision route where needed; check and publish a new version.
5. Create an authorized request, verify real button delivery, click as the confirmed eligible reviewer within ten minutes, and verify one Laravel decision/snapshot and the subsequent outcome. Expired/revision-detail cases open FlowMV; incoming bot identity alone cannot approve.

### Twilio SMS website setup

1. Provision a dedicated Twilio account/subaccount and an owned SMS-capable sender number. Complete applicable sender registration, recipient/trial verification and destination geo-permissions in Twilio. Confirm billing, availability, consent and an operator spending alert/cap. International delivery is not assumed.
2. Set SMS_ENABLED=true, SMS_SIMULATED=false and SMS_WEBHOOK_ORIGIN to the exact public HTTPS API origin. The server uses normal Twilio account SID/Auth Token credentials; do not put a token in source, workflows or logs. Store it through FlowMV's password-confirmed Connect account form.
3. Sign in as organization admin → Connections → SMS → Configure policy. Approve the provider, select allowed ISO countries and conservative volume limits. Connect account, Test and Approve connection.
4. Approve sender using the owned E.164 number. Authorize recipient with its E.164 number, clear label and explicit consent; Basic Lookup checks its country against policy. Renew consent after expiry. Approve a short template with only the four safe variables; create a new template to edit text.
5. Grant workflow permission for one exact action, sender, recipient and template. In Studio add SMS Notification or SMS Send Message, select those resources, review the preview and routing, check and publish. No free-form number or arbitrary body is accepted in workflow JSON.
6. FlowMV sets each message's exact callback URL automatically: `https://API/api/v1/integration-webhooks/sms/CONNECTION_UUID/DELIVERY_UUID`. Expose that route with the configured origin unchanged; do not redirect/rewrite its externally visible URL. Permit standard form callbacks and preserve X-Twilio-Signature. The adapter does not read incoming SMS.
7. Conduct one explicitly authorized low-volume live send. Confirm actual phone receipt, signed status callback and provider console SID/status. Check bounded status reconciliation, geo/consent rejection, retry/uncertain handling and revoked queued work. Local Revoke deletes the saved token/grants; rotate/remove the Auth Token in Twilio separately. Rotate in FlowMV accepts a replacement for the same account and requires fresh approval/grants.

## Live-provider verification checklist and limitations

- [ ] Dedicated Slack app/workspace, real OAuth redirect and token refresh/rotation verified.
- [ ] Slack public HTTPS challenge/signatures/replay handling and actual approved-channel receipt verified.
- [ ] Dedicated Telegram bot token entered by the user, public HTTPS webhook configured and real chat/identity verified.
- [ ] Actual Telegram Approve/Reject, duplicate/stale/revoked/demoted decisions and authenticated revision fallback verified.
- [ ] Dedicated Twilio account, sender registration, billing/geo permissions and consent approved.
- [ ] One real authorized SMS received, callback signature/status and reconciliation independently verified.
- [ ] Restricted production database role, secret manager/key rotation/backup retention, scheduler, queue supervision, TLS and sanitized infrastructure/APM logs reviewed.

All checkboxes remain pending. Simulation flags fail closed outside their exact guarded local environment, but must still be disabled before deployment. Telegram QA tokens and Slack/Twilio QA identities are synthetic only. Provider delivery guarantees, consent rules, billing, account restrictions, deleted app consent and network retries require real-provider evidence. Twilio SDK signature helpers are recommended by the provider; this dependency-free adapter intentionally supports only canonical scalar form callbacks, rejects duplicate parameter names, and is covered by the published vector plus route/account/SID/phone-binding tests. Confirm real callback compatibility before release.

Phase 9C's independent live email/shared-mailbox/document-scanner restrictions remain applicable; this phase does not resolve or expand them. No Gmail/Slack document understanding, AI or additional SMS providers were started.

## Phase 10 readiness verdict

The reusable implementation and simulated communication pattern are established: scoped provider intake, immutable V3 execution, existing human decisions, encrypted durable outboxes and explicit destinations. Phase 10 planning may use this tested implementation foundation. **Production readiness is blocked** on the live checklist and operator deployment review above. No Phase 10 implementation is authorized or included.

## Provider references

- [Slack OAuth](https://docs.slack.dev/authentication/installing-with-oauth/), [PKCE](https://docs.slack.dev/authentication/using-pkce/), [request signatures](https://docs.slack.dev/authentication/verifying-requests-from-slack/), [token rotation](https://docs.slack.dev/authentication/using-token-rotation/), [chat.postMessage](https://docs.slack.dev/reference/methods/chat.postMessage/).
- [Telegram Bot API](https://core.telegram.org/bots/api).
- [Twilio message resource](https://www.twilio.com/docs/messaging/api/message-resource), [webhook security](https://www.twilio.com/docs/usage/webhooks/webhooks-security), [signature reference vector](https://www.twilio.com/docs/usage/security), [Basic Lookup](https://www.twilio.com/docs/lookup/v2-api), [owned phone numbers](https://www.twilio.com/docs/phone-numbers/api/incomingphonenumber-resource).
