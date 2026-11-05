# Authentication, permissions and privacy

Sanctum sessions remain authoritative. Browser writes require the stateful frontend middleware and CSRF. Protected routes resolve active organization membership; revoked membership invalidates access. Rate limits apply to login, password step-up, API and ingress operations. Sensitive connection changes require a password confirmation tied to the same user and a bounded timestamp. Neither a template nor a fake webhook bypasses these checks.

| Operation | Employee | Manager | Admin |
| --- | --- | --- | --- |
| Submit an accessible published form | Yes | Yes | Yes |
| See requests/tasks | Own or authorized participant | Policy-scoped | Organization policy-scoped |
| Approve | Only if assigned/eligible by engine | Eligible assigned role/user | Eligible assigned role/user |
| Author drafts, instantiate, preview | Denied | Workflow author policy | Yes |
| Publish workflows | Denied | Denied | Yes |
| Personal connection creation | Only when organization/provider permits | Same | Same |
| Manage a personal credential | Owner only | Owner only | Owner only; no implicit secret access |
| Shared credentials/provider approval/destination approval | Denied | Denied | Yes |
| Use credential in execution | Explicit workflow/operation/destination grant and runtime checks | Same | Same |
| Analytics/audit | Restricted by existing policy | Scoped | Scoped; minimum cohort protections retained |

Connection ownership is distinct from use. Organization ownership does not grant every member service access. Credentials are encrypted by `CredentialVault` using separately managed versioned keys. Responses, workflow definitions, exports, logs and diagnostics must never contain decrypted tokens or private message bodies. Safe references are UUIDs; authorization must still be rechecked at runtime, including retries.

Phase 10 connection overview hides another member's personal credentials, including from organization administrators. New successful-operation evidence is `simulated` or `provider_response`; missing historical provenance remains `unknown`. Status does not prove liveness. Provider acceptance does not establish recipient delivery.

The demo guard verifies environment, exact connection target, actual PostgreSQL database/user/address/owner, secret installation marker and all simulated transports before accepting synthetic setup. Native uses a dedicated cluster; container mode resolves its private `postgres` service. The local owner role is intentionally privileged for isolated installation and migrations, and is **not** evidence of production least privilege. See the existing [database access restrictions](../database-access.md).

Threat coverage includes cross-tenant IDs, stale grants, revoked credentials, OAuth state/session/PKCE attacks, webhook authenticity/replay, deduplication, decision capability expiry and concurrent task completion. Tests are evidence of covered paths, not a penetration test. Production scanner, PDF sandbox, provider setup, retention approval and recovery remain independent gates.

## Advisory AI

The [AI guide](../integrations/ai.md) documents explicit provider/data consent, ownership versus use, private approval metadata, runtime source/grant checks, transient credentials, encrypted expiring advice, bounded reservations and no model authority. The [Go contract](go-service.md) is signed, replay-protected and private. AI never bypasses scanning, recipient approval, no-self-approval or immutable decision snapshots.
