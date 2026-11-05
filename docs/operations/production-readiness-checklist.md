# Production candidate and closure checklist

**NOT CLEARED for production deployment.** The separate candidate configuration is implemented and tested only in isolated local staging. The showcase Compose file and both showcase databases remain separate. No deployment or real credential rotation is authorized.

## Candidate implementation

`compose.production.yaml` uses PHP-FPM instead of Artisan's development server, a TLS gateway, Next production build, private TLS Go, separate worker/scheduler and private storage. Only the gateway binds a loopback host port. Runtime services drop capabilities, use resource limits and read-only roots with explicitly writable temporary/private paths. Web runtime omits development dependencies; PHP omits tests and Composer. The migrator is an explicit operations-profile service with a different database identity. It never runs automatically on normal application startup.

Use `docker/production/app.env.example` as the non-secret runtime schema. Supply a reviewed `backend/.env.production` through external secret distribution and set `FLOWMV_RUNTIME_ENV_FILE` to that runtime-only file. Keep `DB_OWNER_USER` / `DB_OWNER_PASSWORD` in a separate protected Compose/operator context for the explicit migrator service; they must never appear in API/worker/scheduler environments. Set `PUBLIC_ORIGIN`, `AI_SERVICE_KEY`, `FLOWMV_CERT_DIR` and `FLOWMV_DB_CA_CERT` explicitly; the latter mounts the reviewed public PostgreSQL trust anchor. The staging verifier inspects running container environments without printing values to enforce this separation. The ignored staging profile and generated `.env.staging.runtime` are not production templates to copy wholesale: staging uses an array mail sink and private-network PostgreSQL without TLS, and disables all providers. Production must use approved mail, verified PostgreSQL TLS and real secret management. `EnvironmentChecker` fails production boot for those unsafe settings. To inspect proposed settings before enabling production boot, use a separately configured staging instance and `php artisan operations:check --runtime --no-interaction`. Passing reports checks, not release clearance.

TLS files are separated: gateway mounts web.crt/web.key, Go mounts ai.crt/ai.key, PHP mounts only the public ai.crt trust anchor. Production certificates must have correct public/internal SANs, including loopback for Go health, and secret-file ownership matching service UIDs. Staging generates dedicated short-lived certificates without changing the OS/browser trust store. Never bypass a browser certificate warning. Localhost receives no HSTS policy, avoiding interference with HTTP showcase ports; reviewed public production hosts receive HSTS.

The gateway overwrites forwarded protocol/address, passes HTTPS to FPM, and exposes no raw FPM or Go port. Do not add wildcard trusted proxies. If replacing this gateway, explicitly review proxy trust and host validation. CSP blocks framing, object embedding and foreign base URLs; it is not a full nonce-based script policy.

## Isolated rehearsal commands

From root:

```powershell
node scripts/staging.mjs prepare
node scripts/staging.mjs up
node scripts/staging.mjs verify
node scripts/staging-smoke.mjs
node scripts/staging.mjs backup-restore
```

Prepare creates only `flowmv-phase12-staging` resources, verifies the exact database/user/installation marker, migrates forward and applies existing restricted-role grant files. Secrets and certificates live only in ignored local paths. Repeating prepare preserves keys/data and rechecks ownership. Wrong environment/host/database/role/path/secret shape or unowned volume fails before setup. There is no reset command. `stop` stops only that project and preserves volumes.

The smoke uses trusted HTTPS and ordinary CSRF/register/login/logout routes. It creates one synthetic account, organization, published workflow and completed request on the staging target, then verifies existing records on rerun. Provider transports are disabled, mail is an in-memory sink, and no paid call occurs. Runtime checks prove the role is not owner/superuser/creator and lacks immutable-table mutation grants. Heartbeats require current scheduler and worker processing; they do not prove every queue type is healthy.

## Deployment and recovery procedure

1. Provision distinct database owner/migrator and non-owner runtime identities; private encrypted document storage; vault/service keys independent of APP_KEY; reviewed TLS, DNS, stateful origins and secure session cookie. Set `DB_SSLMODE=verify-full`, its trust anchor, a queue lease above 120 seconds (candidate uses 180), and durable database queue/cache.
2. Build reviewed digest-pinned images from the tested lockfiles. Scan OS packages/images and produce an SBOM without uploading private source or secrets without approval. Review licenses and agree FlowMV's project license separately.
3. Take and verify a pre-migration backup plus required application/vault key versions and encrypted storage. Run forward migrations with the migrator, review/apply runtime grant files, then remove migrator credentials from long-running services. Do not use schema rollback or hosted resets.
4. Start API/Go, then worker/scheduler and web/gateway. Verify readiness, TLS, restricted-role operations, fresh heartbeats, session/CSRF/tenant denials, queue ages and failed-outbox states. Alerts must omit private payloads and credentials.
5. For worker recovery, stop only the failed instance, inspect safe lease/status evidence, restart with bounded attempts and confirm fresh heartbeat and backlog decrease. Do not blindly resend ambiguous paid inference or accepted external messages. Scheduler restart must restore fresh heartbeat and due-job recovery without duplicating decisions.
6. For roll-forward, deploy schema-compatible fixes. An application-level rollback is permitted only when its schema and published registry versions remain compatible. Keep old images/keys/backups until retention and compatibility are resolved; never rewrite published versions or audit timestamps.
7. For compromise, disable affected connections/grants and pause relevant ingress/actions, revoke at the provider, preserve sanitized evidence, rotate application/service/vault secrets through their distinct procedures, restart workers, and test that stale work fails closed. Rotation of real credentials remains an operator action.

The candidate private network deliberately has no provider internet route. Before live use, supply and verify controlled fixed-provider HTTPS egress, DNS, monitoring and certificate renewal without exposing internal ports or weakening signature/destination checks. Scanner/PDF execution must be separately isolated and verified; current scanner configuration requires a loopback daemon in each processing namespace. Providers remain disabled until those gates close.

## Backup evidence and limitations

`backup-restore` refuses to overwrite an earlier backup/restore target. It quiesces only staging API/worker/scheduler, takes a custom-format dump, restores into a different database, compares the table inventory and every table's data checksum, and restarts services in a finally block. The executed rehearsal checked all 83 tables with one synthetic user and a real completed workflow. It did not restore encrypted document objects, perform off-site/PITR recovery, rotate vault keys or measure a production RTO/RPO. Backups contain private data and must be encrypted, access-controlled, retained and tested under an owner-approved schedule. Restore never targets the source or a showcase database.

## Required closure evidence

| Gate | Status / closure |
| --- | --- |
| Live Gmail/Outlook/Slack/Telegram/Twilio/OpenAI/Gemini | Pending dedicated credentials, registered HTTPS endpoints/destinations, and explicit paid-call authorization where applicable |
| Real mail, provider egress and recipient delivery | Pending independent authorized tests; provider acceptance is not confirmed delivery |
| Public PKI, PostgreSQL TLS, secure secret distribution and least-privilege rollout | Candidate prepared; operator environment and external custody pending |
| Production scanner/PDF isolation and file retention | Pending verification; unsupported/unavailable processing must fail closed |
| Encrypted file/key restoration, off-site backup and PITR | Pending production-shaped rehearsal and owner-approved RTO/RPO |
| Container CVE/SBOM review, monitoring/alerts, incident ownership | Pending operational sign-off |
| Accessibility and independent security assessment | See final report; no blanket WCAG/penetration-test claim |
| Production launch | Explicit owner approval required after all critical gates close |

## Final local execution evidence

The final slim web image retains `next.config.ts`; omitting it caused the partial login page to return 500 and was caught by the new health check. The corrected image returns 200 for login. All seven staging services report healthy. Final HTTPS smoke includes login, authenticated dashboard, CSRF, anonymous denial, existing publication/execution, no-store and logout; it reruns without duplicating the fixture. Worker and scheduler were explicitly restarted and later runtime checks confirmed fresh heartbeats, zero queued jobs and zero failed jobs. Signed Laravel-to-Go TLS extraction verified the configured CA and deterministic parity with zero provider calls.

The attempt to scan the public PHP base image with Docker Scout was blocked by its Docker-account login requirement. No private image/source was uploaded and no login was performed. Container CVE/SBOM acceptance therefore remains pending even though Composer/npm/govulncheck reported no advisories at execution time.
