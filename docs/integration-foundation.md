# Integration foundation — security, architecture and acceptance

Date: 28 October 2026. Scope: credential and permission foundation with a local-only mock connector. No Gmail, Slack, Telegram, SMS, or other real provider has been connected or verified. This is not a production release clearance.

## Audit of the inherited implementation

The audit reviewed the current working tree, including the existing uncommitted Phase 8.5 work. Its release gates in [phase85-acceptance.md](phase85-acceptance.md) remain applicable.

| Area | Finding and preservation |
| --- | --- |
| Phase 8.5 | Notification scope, deterministic in-app delivery IDs, stale-claim protection, recovery scheduler, assignment capabilities, analytics filters, and retention restrictions already exist. They were preserved. Existing production supervision, restricted runtime-role deployment, browser-history, accessibility, and original-account browser acceptance gates remain open. |
| NodeRegistry V1/V2 | Five V1 types and three V2 additions have frozen handles. Existing publications continue to stamp V2. Only definitions containing an integration action stamp V3. Unknown registry versions now fail closed. Historical snapshots are not rewritten or revalidated with V3 publication rules. |
| Sanctum | SPA mutations require Sanctum's selected frontend session/CSRF middleware, not simply an authenticated bearer token. Stateful session expiry remains controlled by Laravel's configured database sessions and lifetime. No new personal-access-token authentication bypass was added. |
| RBAC | Active organization membership is checked by scoped locators and execution services. Connection management additionally separates personal ownership from organization administrator authority. Workflow permission is an explicit grant, not implied by owning a connection. |
| Published contracts | Immutable published versions, pinned instance version IDs, content hashes, submission snapshots and workflow audit tables remain intact. New references contain connection UUID, operation and destination only. |
| Execution | The Laravel engine still owns progression and its instance lock. A V3 action reloads the exact stored version and current node, then checks connection/grant/member/destination state. The same gate runs after a delayed job wakes. |
| Notification outbox | Existing notifications retain their own durable outbox and audit guarantees. Integration receipts are separate; mock success does not imply exactly-once delivery to an external provider. SMTP remains at least once as documented in Phase 8.5. |
| Missing foundation before this change | There was no separate credential vault, provider approval policy, connection ownership, scoped workflow grant, OAuth transaction binding, integration audit, or provider destination boundary. These are implemented here rather than exposing arbitrary HTTP execution. |

Installed versions were inspected: Laravel 13.38.0, Sanctum 4.3.3, PHP 8.4, Next.js 16.4.3, React 19.3, shadcn components and Lucide. The Next package did not contain its referenced local docs directory; the official [Next.js routing documentation](https://nextjs.org/docs/app/getting-started/layouts-and-pages) was consulted. OAuth design was checked against [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html). The repository-requested testing-best-practices skill was not present in the available skill locations; existing PHPUnit and Vitest patterns were followed.

## Architecture and trust boundaries

The browser sends a credential once over the existing CSRF-protected session API. The vault encrypts it using AES-256-GCM with a separately provisioned 32-byte key. The authenticated envelope binds ciphertext to its connection ID, detecting ciphertext swaps. Key identifiers and ciphertext are stored separately from connection metadata. Missing, invalid, wrong, or application-reused keys fail closed.

Only connection management services query credential rows. API output is constructed from explicit safe metadata; no credential model is exposed for serialization. Workflows, exports, audit rows, queue jobs and mock receipts contain no decrypted secret. Integration request middleware redacts credentials, password, code, state and signature from ordinary input. Its private holder redacts debug and JSON rendering. Integration exceptions use bounded generic responses even with APP_DEBUG enabled and are excluded from ordinary exception reporting. Do not enable upstream body/query logging or third-party request capture for these routes; a reverse proxy sees traffic before application redaction.

The connector interface declares credential schemas, triggers/actions, configuration validation, credential testing, webhook verification, retry/rate-limit metadata and safe audit fields. The only admitted provider is local_mock. New providers require reviewed code, a registered provider and organization approval; changing a database row cannot introduce executable adapters. No API base URL, arbitrary code, private message body, or unrestricted HTTP node is accepted.

## Schema design

One additive migration creates ten tables. Query-builder access deliberately keeps encrypted records outside general Eloquent serialization.

| Table | Purpose and principal constraints |
| --- | --- |
| integration_providers | Code-backed provider ID, contract version, global enabled flag; only local_mock is initially seeded. |
| integration_policies | One row per organization; personal connection creation defaults to denied when missing. |
| integration_approvals | Unique organization/provider approval. Missing or withdrawn approval denies connection creation and execution. |
| connections | UUID, organization, nullable personal owner, provider, safe label, credential type, enabled/disabled/revoked status. Null owner means organization-owned. Unique ID/organization pair supports tenant-bound child references. |
| credential_secrets | One encrypted envelope per connection, key version and timestamps. Revocation deletes the envelope. No plaintext or last-secret suffix is retained. |
| workflow_connection_grants | Unique connection/workflow/operation grant, organization, authorizing user. Composite connection/organization FK; workflow tenant is checked by management, publication and runtime services. |
| authorized_destinations | Unique connection/destination allowlist with composite tenant FK. Destinations are fixed mock identifiers, never supplied URLs. This allowlist is connection-wide; grants still restrict each workflow's operations. |
| integration_audit_events | Organization, connection reference, actor, safe event code and timestamp. No arbitrary JSON or message body. PostgreSQL and SQLite triggers reject update/delete; the runtime grant script also denies truncate/update/delete. |
| integration_receipts | Unique connection/kind/hash. Actions use the hash of instance UUID and node ID; triggers use the hash of provider event ID. No raw event body or credential is stored. |
| integration_oauth_requests | State hash, actor, organization, session hash, code hash, separately encrypted PKCE context, expiry and consumed timestamp. State and code are single-use and bound to the original browser session. |

Connection revocation is irreversible. Disable is reversible. Revoke deletes secrets, workflow grants and destination entries while retaining safe connection identity, receipts and audit history.

## Connection permission matrix

All sensitive management mutations require an active organization membership and a recent password confirmation. Metadata listing requires an active authenticated session. An organization admin has no special access to another person's personal credential.

| Operation | Personal owner | Organization admin, organization credential | Other member |
| --- | --- | --- | --- |
| Create personal credential | Only if organization policy permits and provider is approved | Same personal policy | Same personal policy for their own credential |
| Create organization credential | Only when also an admin | Yes, approved provider only | No |
| List/manage personal connection | Own only | Own only | No access to somebody else's personal connection |
| List organization connections | If admin; otherwise safe selection metadata only for explicitly granted workflows | Yes | Safe selection metadata only for explicitly granted workflows |
| Test / rename / enable / disable / rotate / revoke | Own only | Yes | No management of another owner's credential |
| Grant/ungrant workflow use | Own connection; execution remains bound to owner's submissions | Admin only | No grant of someone else's connection |
| Execute action | Active owner submitter + explicit workflow/operation grant + allowed destination | Active submitter + active admin authorizer + explicit workflow/operation grant + allowed destination | Organization membership alone grants no access |
| Set personal-creation policy or approve/withdraw provider | Admin only | Admin only | No |
| Decrypt/view/export saved secret | No API operation | No API operation | No |

A personal owner can consent to a named workflow without sharing their credential with another submitter. A removed owner, removed grant authorizer, demoted organization-grant authorizer, disabled connection, revoked connection, withdrawn approval, removed grant or disallowed destination blocks execution, including duplicate/retried deliveries.

## V3 contract

V1/V2 graph types and publication behavior are retained. The UI offers a new Connected action; workflows containing it publish with node_registry_version=3. Its allowed data is:

~~~json
{
  "label": "Review notification",
  "description": "",
  "connection_id": "<safe connection UUID>",
  "operation": "mock.notify",
  "destination": "mock:review"
}
~~~

Extra integration data fields, including credential fields, are rejected and excluded from normalized definitions. Publication requires a valid grant. The engine reloads the pinned version rather than accepting an operation/configuration passed by a queued caller.

External triggers are separately declared in the provider manifest as mock.event. They enter the fixed integration-webhooks/local-mock/{connection} endpoint, not a graph action or arbitrary HTTP node. In this foundation, verified ingress produces a deduplicated receipt only. It does **not** activate workflows. Version-pinned trigger subscriptions, event-to-form mappings and activation delivery are deliberately not exposed until a reviewed connector needs them. A mock receipt is not evidence of a working external automation.

Mock webhook verification signs timestamp.event_id with HMAC-SHA256, accepts a five-minute clock window, requires a UUID event ID and a current trigger grant/destination, and durably deduplicates the event ID. It accepts no message content. Real providers must implement their own signature, issuer, canonicalization and timestamp rules.

Actions execute locally under the existing instance and connection locks; the connector performs no I/O. The receipt and audit event commit together. Concurrent workers serialize and the unique receipt key is the final deduplication boundary. There is one simulated action effect per instance/node. Real-provider retries, refresh tokens, provider idempotency and delivery ambiguity require additional connector-specific implementation; manifest backoff metadata is not a claim that remote retry delivery exists.

## Authentication and OAuth

Sensitive operations require password confirmation in the current authenticated session, expiring after five minutes. Confirmation is limited to five attempts/minute per actor/IP with a distinct limiter key. Integration management is additionally limited to thirty requests/minute and the existing global API limiter remains in place. Laravel session expiration, CSRF protection and active-membership checks are preserved.

The mock OAuth demonstration has start, authorize and callback POST stages, all using the stateful frontend pipeline. State has 256 bits of entropy. PKCE uses S256 with a server-held verifier. The encrypted context binds a fixed local issuer and redirect target, user, organization, session and optional reconnect target. Callback validates state, issuer, code, verifier, expiry and single-use status transactionally. Reconnect replaces the encrypted token under the same connection ID and cannot revive a connection revoked while authorization was pending.

This is a local authorization-code/PKCE simulation, not a real authorization server or real-provider redirect integration. No real OAuth endpoint, client secret, refresh flow, user-supplied redirect URI or provider token exchange is enabled. Real redirect adapters must validate exact registered redirect URIs, transport security and issuer identity and prevent authorization-code/query logging at the ingress layer.

## Migration and rollout report

Migration: backend/database/migrations/2026_10_27_231435_create_integration_foundation_tables.php. Its filename was generated in the execution host's UTC date; this report uses the user's 28 October local date.

- Additive tables and audit triggers only. No workflow snapshot, historical event, credential, user or organization row is rewritten.
- down() throws. Roll forward with a corrective migration; do not use rollback as a production recovery strategy.
- SQLite application tests use in-memory databases. PostgreSQL migration and concurrency checks run only against the pre-existing guarded loopback flowmv_phase85_test database. No hosted migration/reset or browser QA database reset was run.
- The existing PostgreSQL test harness now recreates its guarded disposable database without invoking production-forbidden migration rollback during teardown.
- backend/database/integration-runtime-permissions.sql supplies the additional restricted-runtime grants. It has not been applied to hosted roles. Pair it with the existing runtime-permissions.sql.
- The application is not deployed by these changes. Running a production build does not apply backend migrations.

Before rollout, take a verified backup; run the forward migration using the separate migrator role; apply/review runtime grants as object owner; provision the independent key ring; rebuild configuration and restart web/queue processes. Verify the restricted role cannot mutate audit history or published versions. Exercise TLS, exact Sanctum origins, secure cookies, session expiry and CSRF in staging. Keep the mock disabled outside deliberate demonstrations.

Provision these deployment variables from a secret manager, not source control or frontend environment variables:

| Variable | Meaning |
| --- | --- |
| INTEGRATION_KEYS | JSON object mapping stable version names such as v1/v2 to base64-encoded independent 32-byte keys. No default key is generated. |
| INTEGRATION_ACTIVE_KEY | Version used for new writes; defaults to v1 but fails closed if its key is absent. |
| INTEGRATION_MOCK_ENABLED | Defaults to false. Enable only for local/staging demonstration. |

Never use APP_KEY as an integration key. Back up the encrypted database and key escrow separately, with access controls and restore procedures for each.

## Key rotation procedure

1. Generate a new independent 32-byte key in the organization's secret manager and assign a new immutable version identifier. Do not print it in a command, log, report, ticket or test fixture.
2. Deploy the expanded key ring to all web/worker processes while retaining old keys. Make the new version active everywhere; rebuild cached configuration and restart long-running workers so no writer still uses the former active version.
3. Run php artisan integrations:rotate-keys in the intended environment using the authorized operational role. It locks connection then credential rows, decrypts in process, re-encrypts with the active version, and appends a safe key_rotated event per changed connection. It uses keyset pagination and is restartable; already-current records are skipped.
4. Verify counts grouped by key_version, test a mock connection and inspect only safe audit metadata. Resolve missing-key/decryption failures before proceeding. Never inspect or export plaintext.
5. Retain old keys until every live credential is current, outstanding OAuth contexts have expired or been consumed (at least five minutes), all old workers are gone, and backup/restore retention requirements are met. OAuth contexts intentionally keep their original key version during their short lifetime.
6. Remove old keys from the active deployment only after these checks. Keep protected historical key escrow for supported encrypted backups. Test restoration in isolation. A database restore without the matching key ring cannot recover credentials.
7. For provider-token rotation, use Rotate for API keys/bot tokens or Reconnect OAuth for authorization-code reauthorization. This is distinct from rotating the vault encryption key. Revocation removes FlowMV's local credential immediately; real-provider token invalidation must be implemented and independently verified by each future connector.

If a vault key is compromised, pause credential operations, revoke/replace affected provider tokens through their provider, rotate the vault key, and follow the incident procedure. Re-encryption alone does not invalidate previously exposed provider tokens.

## Validation ledger

Executed local checks are recorded here; no browser accessibility or real-provider verification is implied.

| Check | Result |
| --- | --- |
| Integration PHPUnit security suite | 18 tests, 150 assertions passed; includes V3 publication/execution, queued wake after revocation, callback attacks, session binding, replay, in-place OAuth reconnect, encryption/key rotation, personal-owner restrictions, cross-tenant grants, lifecycle operations, generic debug errors, confirmation throttling and append-only audit |
| Full Laravel regression | 109 passed, 8 PostgreSQL-only skipped, 1,388 assertions in the final broad run; the subsequently added workflow-selection privacy test also passed in the 18-test integration suite |
| PostgreSQL integration race | Full guarded PostgreSQL suite: 8 passed, 97 assertions. Includes two simultaneous integration workers: one true, one duplicate false; one receipt and one action audit |
| Frontend tests | 46 passed in 11 files; includes credential confirmation, policy restrictions, secret removal and revocation UI |
| TypeScript / ESLint | Final TypeScript and ESLint passed; generated build output is explicitly ignored by Git and ESLint |
| Production frontend build | Passed: 23/23 static pages, including /connections, with a separate single-worker output directory |

A sandbox run of Vitest failed before executing tests due to temporary-file rename EPERM. The authorized unsandboxed rerun passed. A form-input UI test initially timed out during overlapping checks; event-driven field setup reduced overhead and the subsequent full run passed. PHP likewise needed an unsandboxed test invocation because the sandbox marked vendor/autoload.php unreadable.

## Next connector implementation sequence

1. Close inherited Phase 8.5 production gates and stage this migration, independent key management and restricted runtime role. Add browser accessibility/responsive acceptance for Connections.
2. Add one reviewed real connector at a time behind an explicit disabled-by-default code/provider registration. Start with a narrowly scoped bot notification action with fixed API hosts and explicit destination identifiers; no arbitrary HTTP node.
3. Implement that provider's exact credential schema, scopes, credential test, destination discovery/approval, safe error mapping, token expiry/revocation behavior and provider-side idempotency. Add a bounded durable action outbox/retry adapter before relying on remote delivery. Revalidate live grants immediately before each attempt.
4. Implement a real OAuth provider only after its registered callback/client configuration exists. Add secure authorization-code exchange, encrypted access/refresh tokens, refresh locking, reconnect, scope downgrade handling and provider revocation. Independently verify against the actual provider using a dedicated test account; never use production tokens in fixtures.
5. Add version-pinned external-trigger subscriptions and event activation only after signature/replay checks, payload minimization, bounded event-to-form mapping and deduplicated activation are tested. Preserve the published version selected by the subscription and revalidate its current security grants before activation/retry.
6. Extend provider-specific tests for rate limiting, Retry-After, timeouts, refresh races, partial failure and accepted-but-unacknowledged delivery. Independently verify actual provider effects before naming an integration working. Gmail, Slack, Telegram and SMS all remain unimplemented/unverified here.
