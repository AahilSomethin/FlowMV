# Phase 9C — Email and document automation acceptance

Updated: 29 October 2026 (Asia/Tashkent). No commits or pushes. No hosted database resets or production credential changes. No AI integration, Slack, SMS, or later-phase work.

## Acceptance status

**Implementation and simulated acceptance are complete within the explicit restrictions below.** Live Gmail/Outlook, real antivirus scanning, and the PDF container are **not live-verified**. A provider acknowledgement means **accepted**, not proof of inbox delivery or exactly-once sending.

The original QA workflow and its published version 1 were preserved. Its original request `ca5a0447-fc21-420e-8013-bbfe8dc37f75` progressed from incomplete DOCX → authenticated confirmation → Revision → corrected data → Manager approval → simulated HR email → completed. Version 2 adds an explicit applicant outcome reply. It was created, saved, reloaded, validated and published through the website; version 1 was not rewritten. Its separate synthetic request `554b9061-c2fe-47e3-b975-5ed2dc65431e` completed the same correction and Manager path, then queued both HR and applicant outcome messages. Both were accepted by the fake transport. The two distinct input emails produced exactly two requests, five accepted actions total (two correction replies, two HR notices, one version-2 applicant outcome). The QA requester has the Admin role; it used the authenticated submitter/revision path, and a separate Manager made both decisions. Employee-specific privacy/permission restrictions are additionally covered by automated tests.

## Architecture and security audit

The existing Laravel engine remains authoritative. V1/V2 type/handle contracts remain unchanged; V3 adds six explicit email/document types. Publication stores immutable graph, form, registry and document-template snapshots. External subscriptions pin the workflow version. Publication pauses old subscriptions for new intake; existing instances retain their version and continue subject to current authorization.

The existing Sanctum stateful sessions, CSRF, membership/RBAC, password step-up, no-self-approval, revision counters, decision snapshots, notification outbox and integration audit protections are reused. Email headers never authorize employee actions. Canonically fetched messages may identify an active member by their verified email address, but that member must authenticate and confirm the intake inside FlowMV before any workflow instance is created. Unknown senders cannot create internal requests. Personal mailboxes accept only their owner as the initiating member.

Email tokens, refresh tokens, OAuth state context/PKCE verifier, synchronization cursors/clientState, message bodies, attachment fetch references, intake context, extracted text and outbound bodies use authenticated vault encryption with context binding and versioned keys independent of APP_KEY. Workflow JSON contains safe connection references and configured templates, never stored credentials. Dedicated private HTTP factories prevent global request instrumentation from capturing provider secrets. Provider errors and audit events contain safe codes/counts, not private message/document content. Integration request middleware redacts tokens, authorization codes, notification bodies, uploads and preview/intake inputs before exception handling.

Gmail push requires a verified Google RS256 JWT, pinned issuer, configured audience and verified service-account email, then the configured Pub/Sub subscription and mailbox. Graph requires constant-time verification of subscription clientState and the stored subscription ID. Validation handshakes return bounded plain text only. Notifications are synchronization hints; canonical content is fetched through the approved mailbox. Unique notification, message, intake and outbox keys prevent duplicate activation. Automatic-message headers prevent loops.

Every execution and queued delivery rechecks organization membership, active connection/provider approval, capability, current grant authorizer, exact recipient, credential generation and immutable node/version references. Reconnect validates the same account, removes grants, increments generation, pauses subscriptions and requires fresh approval. Disable/revoke pauses new triggers and cancels pending deliveries. Revocation removes FlowMV's stored credential; provider-side consent must also be revoked through Google/Microsoft. Previously authorized private request evidence remains governed by request visibility and retention.

Outbound work is durable and bounded to five attempts. Provider Retry-After, per-mailbox/recipient/organization limits, timeouts and safe error recovery apply. Interrupted or ambiguous sends become **uncertain** and require an administrator's explicit duplicate-risk acknowledgement before retry. Stable RFC Message-ID is an aid, not a provider idempotency guarantee. No unrestricted HTTP node, arbitrary API host, user code, remote document URL, or public document endpoint exists.

## Schema and migration report

`backend/database/migrations/2026_10_28_191041_add_email_document_foundation.php` is additive and forward-only; rollback deliberately refuses destructive reversal. It registers Gmail and Outlook and introduces:

| Area | Tables |
| --- | --- |
| Connection/OAuth | email_mailboxes, email_oauth_attempts |
| Notification/sync/fetch | email_sync_state, email_notification_receipts, email_fetch_queue, email_attachment_fetches |
| Recipient authorization | email_destinations, email_workflow_grants |
| Evidence | email_messages, private_documents, document_templates, workflow_document_evidence |
| Workflow activation/delivery | email_trigger_bindings, email_intakes, email_action_outbox |

Composite tenant/connection foreign keys, unique deduplication constraints, and immutable-template triggers supplement server policies. Existing foundation/Telegram tables and audit guarantees are retained.

Applied all 11 forward migrations only to the newly created loopback `flowmv_phase9c_qa` database at 127.0.0.1:55485. `flowmv_phase9b_qa`, `flowmv_phase85_qa`, previous QA accounts/workflows, and hosted databases were preserved. Automated PostgreSQL migrations use only explicitly guarded disposable `flowmv_phase85_test`. **Deployment migration and restricted runtime grants remain pending for every non-QA target.** Apply `backend/database/integration-runtime-permissions.sql` after the migration as object owner; document templates and integration audit rows must not be mutable by the runtime role.

## Connection permission matrix

| Operation | Personal mailbox | Organization mailbox |
| --- | --- | --- |
| Create | Member only if personal policy and provider approval permit | Administrator |
| Connect/test/reconnect/disable/revoke/downgrade | Owner | Administrator |
| Approve mailbox/provider/recipient | Administrator | Administrator |
| Grant/revoke a workflow operation | Owner, after mailbox and exact-recipient approval | Administrator |
| Use in a workflow | Explicit operation/destination grant; initiator must be owner | Explicit operation/destination grant and active member |
| Confirm incoming intake | Verified member matching canonical sender and mailbox ownership policy | Verified member matching canonical sender |
| View/download evidence | Owner or authorized request participant/connection manager, same tenant, unexpired, clean | Same explicit visibility checks |
| Approve internal request | Eligible Manager under existing no-self-approval policy | Same |

Sensitive operations require a session-bound password confirmation no older than five minutes, plus current server authorization. Being an organization member does not grant mailbox execution access.

## Document implementation and restrictions

Private PDF/DOCX bytes use a dedicated non-served disk at `backend/storage/app/integration-private`, random tenant/UUID paths, integrity checks and encryption. File magic/MIME, names, size/count, upload quotas and tenant/owner checks run before processing. Default maximums are 8 MiB per file, five per message and 30-day retention (configurable within bounds). Expiry removes encrypted files and extracted text; historical metadata/decision snapshots remain. An authenticated download returns attachment/octet-stream with no-store, nosniff and restrictive CSP, never a public/signed URL.

Production scanning uses loopback ClamAV INSTREAM and fails closed on infection or scanner outage. DOCX extraction uses bounded ZipArchive/XMLReader processing, rejects traversal, excessive expansion/entries, macros, embedded binaries, DTD/entities and external relationships, and cleans temporary plaintext after processing. PDF parsing requires an operator-provisioned digest-pinned Docker/Podman image containing pdftotext: no network, readonly filesystem, unprivileged user, dropped capabilities, memory/CPU/PID/page/output/time limits and no image pull during execution. Without the runtime, PDF stays quarantined with parser_unavailable. Image-only/OCR PDFs and AI extraction are not implemented.

Templates support text, identifiers, email, dates/date order, numbers, enumerated choices and conditional supporting-document requirements. Deterministic extraction reads label/value lines, reports conflicting values as uncertain, and distinguishes complete, missing information, invalid information, and unable to determine. Field presence is not authenticity. Corrections use existing authenticated form revisions with bounded attempts; original document bytes and decision data snapshots remain unchanged. Replacement-document version editing is not implemented; this acceptance uses original evidence plus corrected structured data.

## Verification evidence

Checks were run one at a time because of local RAM pressure. Final browser verification used the successful production build. The frontend-only snapshot-preview correction was followed by the final 58-test Vitest run, ESLint, TypeScript, Pint and a new successful production build; the verified Laravel/PostgreSQL implementation was unchanged.

| Check | Verified result |
| --- | --- |
| Laravel `php artisan test --compact` | 167 passed, 10 PostgreSQL-only skips; 2,234 assertions |
| PostgreSQL `php vendor/bin/phpunit --configuration=phpunit.pgsql.xml --display-warnings --display-phpunit-deprecations` | 10 passed, 107 assertions, exit 0 |
| Vitest `node node_modules/vitest/vitest.mjs run` | 58 passed across 14 files (final run with --maxWorkers=1) |
| ESLint `node node_modules/eslint/bin/eslint.js .` | Passed |
| TypeScript `node node_modules/typescript/bin/tsc --noEmit` | Passed |
| Laravel Pint `php vendor/bin/pint --dirty --format agent` | Passed; formatted changed PHP |
| Production build | Passed, exit 0; 27 pages; single worker and 2 GiB Node heap limit |

Focused email/document tests cover OAuth state/session/provider/PKCE binding, replay and expiry, token encryption/refresh/rotation, personal privacy, cross-tenant access, forged notifications, clientState/JWT checks, duplicate/out-of-order events, cursor reconciliation, safe continuation hosts, scanner failure, MIME spoofing, traversal/XXE/macros/bombs, quarantine/download access, deterministic validation, template immutability, private dry runs, revoked confirmation, stale recipient permissions, bounded retries, ambiguous/interrupted sends and leak prevention. PostgreSQL uses two real processes to prove one refresh with a rotated refresh token and one notification receipt, alongside existing execution/Telegram races. The workflow test exercises correction → Manager approval → approved HR email → applicant outcome email, all through fake transports.

A Windows temporary directory cleanup race and a frontend step-up expiry timer lint issue were found and fixed. Final browser review also found that task-list previews incorrectly called deliberately omitted decision snapshots unrecorded. The UI now directs users to full request history for omitted snapshots while distinguishing genuinely absent snapshots; its regression test passed. The snapshot-list API and its privacy guarantees were preserved. Generated QA build directories are excluded from ESLint. Existing Vite native-config warning is informational. No live-provider success is inferred from these tests.

## Browser acceptance matrix

| Journey | Evidence/status |
| --- | --- |
| Normal signup, workspace creation, signed verification | Passed with synthetic QA accounts |
| Manager invitation, verification, supported login | Passed; normal invitation acceptance, no database role shortcut |
| Provider approval, password step-up, simulated OAuth, mailbox approval | Passed through website |
| Exact HR/sender recipient approval and separate workflow grants | Passed |
| Template creation; six nodes in editor; safe field mapping | Passed |
| Draft save, reload, server validation, immutable publication | Passed, versions 1 and 2 retained |
| Missing DOCX intake and sender confirmation | Passed; no instance before confirmation |
| Original request correction and Manager approval | Passed; same request/version; original evidence retained |
| HR email | Simulated provider accepted; original request completed |
| Manager cannot administer connection or confirm another member intake | Passed: controls absent; API policies covered by tests |
| Manager private evidence download | Passed authenticated download |
| Final production snapshot-preview fix | Passed: omitted snapshot directs to full history; false “not recorded” message absent. Full request displays the actual decision snapshot |
| Applicant outcome on version 2 | Passed: request 554b9061-c2fe-47e3-b975-5ed2dc65431e completed; HR and applicant actions accepted by the fake provider |
| Private upload, inspection and dry run | Passed: encrypted quarantine → synthetic clean scan → extracted fields → missing_information; dry_run=true, matched=true, external_delivery=false |
| Responsive/themes | Passed at 390×844 dark and 1280×900 light, no horizontal overflow; completed request, evidence and timeline readable. Final mobile Connections also checked at 390×844 without horizontal overflow |
| Keyboard | Password step-up submitted with Enter through the provider approval form |
| Duplicate replay | Passed: replay of the existing Outlook notification left exactly 2 notification receipts, 2 requests and 5 accepted outbound actions |
| Gmail connect/test/disable/enable/revoke | Passed with a separate synthetic mailbox; revoked status shown, action controls removed, and credential_secrets row absent. The working Outlook mailbox remains enabled |

QA frontend: http://localhost:3007/connections/email. QA API: http://localhost:8007. Existing local mail catcher: http://127.0.0.1:18026 (no relay; old messages preserved). Synthetic account details remain only in ignored local QA storage. Simulation is guarded by actual loopback address, exact database name, phase9c environment, feature flag and synthetic-only client settings. It cannot run against hosted or ordinary application databases. Browser event emission uses simulated Outlook canonical reads and verified Graph clientState; Gmail signed push is exercised by automated tests. QA scanning is explicitly synthetic, not an antivirus claim.

## Operator setup before live testing

Use a dedicated test environment/account and public HTTPS frontend/API. Do not enter real credentials on the simulation instance or paste them into Codex chat. Operator OAuth application secrets go into the server's secret manager/environment; members authorize their own accounts through the authenticated Connections website.

1. Back up the target, inspect migration status and apply only forward `php artisan migrate --force`. Apply restricted runtime grants separately. Configure stateful Sanctum frontend origin, secure/HttpOnly session cookie, SameSite policy, HTTPS proxy trust, CSRF and session lifetime. Never run test resets on hosted Supabase.
2. Set `INTEGRATION_EMAIL_ENABLED=true`, `INTEGRATION_EMAIL_SIMULATED=false`, `INTEGRATION_EMAIL_WEBHOOK_ORIGIN=https://api.example.com`, and `FRONTEND_URL=https://flowmv.example.com`. Frontend `NEXT_PUBLIC_API_URL` must point at that API when building. Disable request/query/body logging for OAuth callback and webhook paths at the proxy/APM level. Disable debug error output. Callback pages use no-referrer/no-store and immediately remove code/state from browser address history.
3. Provision independently random 32-byte base64 vault keys using a secret manager: `INTEGRATION_ACTIVE_KEY=v1` and `INTEGRATION_KEYS={"v1":"BASE64_KEY"}`. These must not be derived from APP_KEY. Use a distinct key per environment. Do not expose the key in frontend variables, source, logs or tickets.
4. Configure ClamAV with current signatures on loopback (`DOCUMENT_CLAMAV_HOST=127.0.0.1`, `DOCUMENT_CLAMAV_PORT=3310`). Test clean, infected and unavailable cases. Provision a reviewed, patched container image locally by digest and set absolute `DOCUMENT_CONTAINER_RUNTIME` plus `DOCUMENT_PDF_IMAGE=registry/image@sha256:DIGEST`. Verify the sandbox with representative PDF fixtures. Keep private storage outside the web root with restrictive filesystem permissions and encrypted backups. Optional `DOCUMENT_MAX_BYTES`, `DOCUMENT_MAX_COUNT`, `DOCUMENT_RETENTION_DAYS` apply bounded limits.
5. Run a supervised queue worker, e.g. `php artisan queue:work --tries=1 --timeout=90`, with database/Redis retry_after greater than the largest job timeout (use at least 120 seconds). Run `php artisan schedule:run` every minute, with a single shared scheduler lock. `integrations:recover-email` handles periodic sync, retry dispatch, interrupted-send recovery, retention and orphan cleanup; normal approval starts mailbox sync automatically. Watches/subscriptions renew within 12 hours of expiry; reconciliation polls at most every five minutes when idle. Monitor safe health/error codes and uncertain deliveries.

### Gmail / Google Cloud

1. Create a Google Cloud project; enable Gmail API and Pub/Sub. Configure OAuth consent screen, approved domains and a dedicated test user while in testing. Create a **Web application** OAuth client. Register the exact redirect `https://flowmv.example.com/integrations/email-callback/gmail` and your HTTPS frontend origin. Complete Google's required app verification/security review before wider use of restricted Gmail scopes.
2. Set `GOOGLE_MAIL_CLIENT_ID`, `GOOGLE_MAIL_CLIENT_SECRET`, `GOOGLE_MAIL_REDIRECT_URI` to that exact registered callback. Requested scopes depend on selected capabilities: `https://www.googleapis.com/auth/gmail.readonly` for read; `https://www.googleapis.com/auth/gmail.send` for send; plus `openid email` for a stable verified identity. No Gmail modify/full-mailbox password permission is requested. Offline consent supplies refresh access; a reconnect can be needed if Google does not issue a refresh token.
3. Create topic `projects/PROJECT/topics/TOPIC`; grant Gmail's publisher identity `gmail-api-push@system.gserviceaccount.com` permission to publish to it. Create an authenticated push subscription to `https://api.example.com/api/v1/integration-webhooks/email/gmail`. Configure its push service account and the Pub/Sub token-creation IAM permissions documented by Google. The topic must belong to the same project used for watch requests.
4. Set `GOOGLE_MAIL_PUBSUB_TOPIC`, `GOOGLE_MAIL_PUBSUB_SUBSCRIPTION` (full resource name), `GOOGLE_MAIL_PUSH_AUDIENCE` (exact configured audience, normally the webhook HTTPS URL) and `GOOGLE_MAIL_PUSH_SERVICE_ACCOUNT` (exact push service-account email). Verify signed JWT delivery independently. History IDs are encrypted; expired history triggers bounded inbox reconciliation. The scheduler renews watches before expiration.

### Outlook / Microsoft Entra

1. Register a Web application in Microsoft Entra with the intended tenant/account type. Register exact redirect `https://flowmv.example.com/integrations/email-callback/outlook`. Create a server-side client secret and record its expiry. Set `MICROSOFT_MAIL_CLIENT_ID`, `MICROSOFT_MAIL_CLIENT_SECRET`, `MICROSOFT_MAIL_REDIRECT_URI`, and `MICROSOFT_MAIL_TENANT` (organizations, consumers, or a tenant UUID).
2. Configure **delegated**, not application, permissions. Selected capabilities request Graph `Mail.Read` and/or `Mail.Send`, plus `User.Read` for stable account identity and `offline_access` for refresh. Obtain tenant administrator consent where policy requires it. This implementation uses the connected account's `/me` mailbox. True delegated access to a separate Microsoft shared mailbox (`/users/...`, Mail.Read.Shared/Mail.Send.Shared) is **not implemented**; organization ownership means FlowMV-managed sharing of the connected account's credential, not delegated shared-mailbox support.
3. Expose `https://api.example.com/api/v1/integration-webhooks/email/outlook` to Graph for plain-text validation, change notifications and lifecycle events. Approval starts encrypted clientState subscription creation. The scheduler renews the one-day subscription and reconciles inbox delta pages; missed/removed notifications and expired cursors trigger recovery. Do not substitute arbitrary notification or continuation hosts.

### Website steps for a real account

1. Sign in to FlowMV. As an administrator, open **Connections → Email & documents** and approve the intended provider, confirming your FlowMV password. Enable personal connections in the existing general Connections policy only if required.
2. Select provider, connection name, ownership and only needed read/send capabilities. Select **Continue to provider**. Authenticate and consent on Google's or Microsoft's website; FlowMV never asks for an email password or displays OAuth tokens.
3. On the FlowMV callback, confirm your FlowMV password to finish. Return to Email Connections. An administrator selects **Approve mailbox**; the owner/admin selects **Test**. Verify health and actual watch/subscription registration independently.
4. Administrator selects **Approve recipient** for each exact sender allowed correction/outcome replies and the exact HR notification recipient. Owner/admin grants the draft workflow `email.receive`, `email.reply` for that sender and `email.send` for HR. A recipient being approved alone does not grant workflow use.
5. Create an immutable document template and optional review draft recipe, or configure the six nodes in Workflow Studio. Keep request fields optional; Document Validation enforces required document data so missing values can enter Revision. Map fields, choose exact recipients and permitted safe variables, connect every branch, bound correction attempts, Save, Check, then explicitly Publish.
6. Send a dedicated real test email with a known clean attachment from the verified FlowMV member address. Confirm its intake inside FlowMV, correct missing information, sign in as a separate eligible Manager, review/download the evidence and decide. Verify actual HR and applicant inbox receipt and headers, audit timeline, provider logs, duplicate/revoked/failure paths and renewal. Do not treat FlowMV's accepted status as independent inbox evidence.
7. Disable pauses triggers; Enable requires explicitly resuming a pinned trigger. Reconnect clears grants and approval. Revoke locally, then revoke consent/provider subscriptions in the provider portal when retiring the connection. Review uncertain deliveries before any retry.

## Key rotation procedure

Add a new independent key version while retaining old keys; select it as active in the secret manager, reload configuration and restart supervised workers. Run `php artisan integrations:rotate-keys` against the explicitly verified target. It re-encrypts credentials, OAuth/sync/fetch/message/intake/outbox context, extracted text and document files under row locks; document files switch atomically and old ciphertext is removed after commit. Verify every live ciphertext key_version/extracted_key_version and a controlled decrypt/use before retiring an old key. Keep old keys for encrypted backups and still-retained ciphertext; simulation cache is disposable and may require reconnect after key retirement. Never rotate APP_KEY as a substitute or delete old keys prematurely.

## Remaining live dependencies and operational limits

No real Gmail, Outlook or Telegram integration success is claimed by this phase. Required before declaring Phase 9C operational: registered OAuth apps and consent, server-side client secrets, public HTTPS callbacks/notifications, Google authenticated Pub/Sub IAM, verified Graph subscription lifecycle/delta recovery, real mailbox refresh/revocation tests, real clean/infected scanner tests, configured and verified PDF sandbox, supervised worker/scheduler, secure private storage/backup retention, deployment migration/runtime grants, and independent HR/applicant inbox evidence.

No OCR, AI/Gemini/GPT extraction, anonymous correction links, attachment forwarding, arbitrary recipients, delegated separate Microsoft shared mailboxes, or replacement-document editor is implemented. Corrections and approvals remain authenticated. Provider scopes can be downgraded locally immediately; revoke/re-consent at the provider to remove the provider-side grant itself. External OAuth client-secret rotation and expiry remain operator responsibilities.

## Primary provider contracts

- [Google web-server authorization and offline access](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Gmail push and watch renewal](https://developers.google.com/workspace/gmail/api/guides/push)
- [Gmail history reconciliation](https://developers.google.com/workspace/gmail/api/guides/sync)
- [Authenticated Pub/Sub push](https://docs.cloud.google.com/pubsub/docs/authenticate-push-subscriptions)
- [Microsoft authorization code and PKCE](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow)
- [Graph notification webhooks](https://learn.microsoft.com/en-us/graph/change-notifications-delivery-webhooks)
- [Graph lifecycle recovery](https://learn.microsoft.com/en-us/graph/change-notifications-lifecycle-events)
- [Message delta synchronization](https://learn.microsoft.com/en-us/graph/api/message-delta)

## Local visual evidence

Ignored QA artifacts are preserved under `backend/storage/app/phase9c-qa/`: `completed-request-desktop.png`, `completed-request-mobile-dark.png` and `connections-mobile.png`. These show synthetic data only. The full request page displays the actual decision-time snapshot and separate accepted HR/applicant history events. No provider access token or password is present in the screenshots.

## Resume/recovery verification

The local API, frontend and PostgreSQL cluster had stopped between sessions. The existing PostgreSQL 18 data directory was restarted on loopback; no initialization, reset, rollback or migration was run during recovery. The existing Phase 9C API, supervised-style local worker and final production frontend were restarted on 127.0.0.1:8007/3007. Read-only checks reconfirmed both requests completed, five actions accepted, the Outlook credential retained, the spare Gmail credential revoked/removed, and all four local FlowMV databases present. Previous Phase 9B data was preserved.

Final sign-off: **complete for the documented implementation and simulated acceptance scope; not operational/live-verified**. The final production frontend serves the completed version-2 request at `http://localhost:3007/requests/554b9061-c2fe-47e3-b975-5ed2dc65431e`. No additional work was started beyond Phase 9C. A final Next.js feedback review found no qualifying framework issue to report.
