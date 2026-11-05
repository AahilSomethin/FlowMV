# Operational runbooks

These procedures describe current mechanisms and proposed production practice. The [current acceptance report](../phase12-final-acceptance.md) distinguishes executed isolated staging checks from production gates. Local restricted-role and database-restore rehearsal does not establish production deployment, complete disaster recovery or scanner certification.

## Configuration and migration

Review the exact database identity and ownership before any migration. Use `php artisan migrate:status` and review pending SQL against a clone. Apply forward-only migrations; never use migrate:fresh/reset on hosted, QA or demonstration targets. Phase 10 adds nullable `integration_audit_events.evidence_source` without historical backfill and `demo_datasets` for owned synthetic manifests. Deployment must apply the evidence column **before** code writes that field. Existing snapshots, audit rows and registry versions stay unchanged. Verify runtime-role privileges using the existing [database access guide](../database-access.md) and `backend/database/integration-runtime-permissions.sql`; the demo owner role is not a production runtime role.

Generate and manage `APP_KEY` independently from `INTEGRATION_KEYS`. Keep secret files ignored and access restricted. Do not bake secrets into images or frontend builds. Build-time public API URLs must match the deployed origin. Enable HTTPS, trusted origins, secure cookie settings and debug=false under a reviewed production configuration. The local PHP server is for the showcase. Phase 12 implements a separate supervised FPM/TLS candidate and local database backup rehearsal; external secret custody and production-shaped recovery remain gates. The [candidate checklist](production-readiness-checklist.md) is authoritative for this distinction.

## Workers and scheduler

The demo runs `php artisan queue:work --sleep=1 --tries=3 --timeout=120` with database queue retry_after=180, plus `php artisan schedule:work`. Use a supervised worker and `schedule:run` each minute for a future deployment. Restart long-lived workers after code/configuration changes. Current scheduled recovery includes delays, notification outbox, Telegram, email, Slack and SMS. Inspect `routes/console.php` for authoritative schedules.

Outbox rows carry safe statuses, attempts, available times and lease claims. Recovery handles expired claims; provider actions recheck current authorization/generation. Inspect failed/uncertain rows before retrying. An uncertain send may already have reached the provider; require acknowledgement of duplicate risk. Do not change stored outcomes to make a demo appear healthy. Do not blindly replay all jobs or suppress audit events. SMS reconciliation can advance provider status; simulated reconciliation remains simulated evidence. Delivery acceptance and final delivery are different states.

## Vault key rotation

1. Inventory encrypted credential, OAuth-state, event, document and outbox records and key versions. Take a recoverable encrypted database **and private-storage** backup together with separately protected old keys.
2. Add a new independently generated 32-byte base64 key to the secret map under a new version; retain old versions for reads. Change `INTEGRATION_ACTIVE_KEY` for new writes and restart workers.
3. Review `php artisan integrations:rotate-keys --help` and use its bounded forward re-encryption command. Provider tokens are decrypted only within the vault/connector boundary, never returned by the command. Verify key version coverage and affected connector tests against a clone first.
4. Recheck old pending jobs, private documents and rollback/recovery requirements. Remove old keys only after no retained data or restoration policy requires them and authorized operators approve retirement. Rotation does not independently verify provider connectivity.

See the existing [foundation design](../integration-foundation.md) for historical key rotation evidence. Do not mistake changing APP_KEY for rotating vault keys.

## Monitoring and incidents

Monitor failed jobs, queue age, lease expiry, outbox attempt/error counts, trigger pause state, OAuth reauthorization, document/scanner failures, rate limits and database latency. Log event IDs, workflow/version IDs and safe error codes; omit credentials, private text and submitted documents. Avoid high-cardinality sensitive destination labels. Alert on sustained failures and access anomalies, not every fake event.

For suspected compromise: restrict the affected connection/provider, pause intake, revoke grants and rotate/revoke the provider token through authorized operators. Preserve append-only evidence and claim/outbox state. Investigate affected tenant, actor, destination and credential generation. Do not delete audit data, expose plaintext in tickets or automatically resend ambiguous actions. Restore service only after reauthorization and focused regression checks. A production incident-response rehearsal remains unverified.

## Backup, recovery and retention

Proposed backup scope: consistent PostgreSQL backup, encrypted private documents, app/vault key versions, configuration metadata and recovery manifests under separate restricted custody. A DB-only restore cannot recover missing documents or keys. Rehearse restoration into a new isolated target, verify membership, pinned versions, snapshots, references and encryption before any workers start. Keep transports disabled until duplicate/replay risk is reviewed. No production restore was executed in Phase 10.

Follow the existing [submission snapshot retention policy](../submission-snapshot-retention.md); do not bypass immutable snapshot/audit protections. Email/document retention is configured and bounded in `config/email_integrations.php`; document expiry must not erase required decision evidence without an approved policy. Existing [cache/rate-limit guidance](../cache-and-rate-limits.md) describes ephemeral limits, not permanent access grants. Production retention sign-off, backup schedules, recovery objectives and incident ownership remain open gates.

## Phase 11 AI operations

Use the [Go service guide](../architecture/go-service.md) for runtime variables and private TLS. `integrations:recover-ai` is scheduled each minute: expire ciphertext, fail stale processing without automatic paid retry, recover pending ID references and resume pinned outcomes. Monitor pending age, manual-review failures, budget reservations, token reports, service rate limits and unavailable branches without collecting bodies or keys. Vault re-encryption includes AI results; test with old/new key versions before retiring keys. Runtime grants for the new tables are documented in `backend/database/integration-runtime-permissions.sql`; the demo ledger is not a production runtime grant. Service-key rotation requires coordinated Laravel/Go secret updates and graceful worker restart; pause new analyses during the transition and do not retry ambiguous provider work. This procedure is not a claim of a production rotation rehearsal.

## Phase 12 candidate and rehearsed recovery

The [production checklist](production-readiness-checklist.md) is authoritative for the separate PHP-FPM/TLS candidate, runtime-only versus migrator secrets, operations heartbeats, restricted-role verification and the executed isolated database restore. Earlier production procedures remain proposals until their named gates close. No production deployment or encrypted-document restore was performed.
