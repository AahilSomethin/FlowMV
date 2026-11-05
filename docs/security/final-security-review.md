# Final security review

This is a source/dependency review and regression record, not an independent penetration test. The [Phase 12 ledger](../phase12-final-acceptance.md) records executed checks and unresolved gates. No source finding authorizes production release.

## Threats and controls

| Boundary | Reviewed controls | Verification / limitation |
| --- | --- | --- |
| Browser/session | Sanctum stateful origins, CSRF, expiry, step-up, no-store, no optimistic approvals | Negative regression suites plus website checks; server authorization remains authoritative |
| Tenant/member | Scoped queries, live membership/role checks, eligible reviewer and no-self-approval | Cross-tenant, revoked member, stale task and PostgreSQL concurrency suites |
| Publication | V1/V2/V3/V4 pinning, immutable versions/events/snapshots | Compatibility tests; restricted runtime role cannot mutate protected tables or own schema |
| Vault | Independent versioned encryption key, context binding, rotation and no secret exports | Existing encryption/rotation/retention regressions; external key custody remains an operator gate |
| Go | HMAC exact bytes, boot audience, bounded nonce window, fixed hosts, strict bounded JSON, TLS and deadlines | 46 Go tests, vet, Linux race suite and govulncheck; no model tools or domain authority |
| Provider ingress/egress | Signatures/state/PKCE, identity links, dedup, grants/destinations rechecked at execution | Deterministic simulated regressions; all live-provider evidence pending |
| Documents | Private encrypted storage, quarantine, bounded ZIP/XML parsing and scanner fail-closed | Actual DOCX parser tested; production scanner/PDF isolation and encrypted-file restore are not verified |
| AI | Approved source/category/model/operation, minimization, serialized reservations, validated advisory outputs and recheck after response | Unit/security/queued-concurrency tests; generated advice never decides or selects arbitrary recipients |
| Prefetch | Route shells only; bounded intent; no private API data cache | Budget/focus/cancellation tests, no-store tests, refreshed role boundaries; browser data always reauthorized |
| Operations | Separate migrator/runtime roles, private service ports, TLS Go, supervised worker/scheduler, safe logs | Isolated staging smoke/role/heartbeat and backup/restore; production deployment not performed |

## Confirmed remediation

Composer audit on 2026-11-05 found eight advisories across http-foundation, http-kernel, mime, routing and yaml. Compatible patches were applied: 7.4.23, 7.4.23, 7.4.22, 7.4.23 and 8.1.11 respectively. This is patch remediation, not a Symfony/Laravel major migration. The [upstream HTTP kernel advisory](https://github.com/symfony/symfony/security/advisories/GHSA-wg5q-m2ww-f3c9) describes an inline-fragment forwarded-header issue; direct exploitability in FlowMV was not asserted. The raw initial advisory inventory is private. Post-update Composer audit reports zero.

npm initially reported 13 low/moderate dependency-chain findings, with no high/critical findings. The application used shadcn's CSS but not its generator/registry JavaScript. The exact required stylesheet was retained with its MIT notice and the unused CLI dependency removed. A targeted minimatch override pins affected versions to 11.0.4, without downgrading Next or unrelated packages. npm audit now reports zero; build/lint/regressions must verify compatibility. See [third-party notices](../../frontend/THIRD_PARTY_NOTICES.md). No FlowMV project license was invented.

Installed Next 16.4.3 is newer than the [October upstream patched baseline 16.4.1](https://nextjs.org/blog/nextjs-security-update-october-14-2026). This comparison is not a substitute for current audit results. Go govulncheck v1.9.0 found no reachable known vulnerabilities with Go 1.27.3. Advisory databases change; repeat scans before release.

Private API responses and fetches now explicitly use no-store. The shell no longer mounts hidden page children before authentication. User/organization/role changes remount private UI state, and visible sessions revalidate each minute. This UI refresh interval is not an authorization lease; a denied server request or queued job cannot use previously cached permission. The prefetch metadata budget is cleared on logout/unmount and scope changes. No secrets, document bodies, audit exports or AI advice are prefetched.

Session generation regressions cover an older 401 arriving after a new login, logout racing an in-flight refresh, and a sibling local profile replacing the CSRF cookie. Unkeyed POSTs are never automatically replayed. Go shutdown now waits for active requests to drain before main exits; a held-request regression and Linux race suite verify that lifecycle. Long-running candidate services receive no database migrator credentials; the staging verifier inspects their environment without printing values. Go receives neither database nor vault/application keys.

Go readiness now honors configured ports and verifies TLS against a trusted certificate rather than attempting hardcoded plaintext. An incomplete certificate/key pair fails startup. Production configuration refuses debug, insecure URLs/cookies, simulation, logging mail, invalid vault separation, unverified database TLS and an inadequate queue lease. Staging runtime checks report only safe findings, queue counts and heartbeats. A delayed heartbeat retains its enqueue timestamp so a backed-up queue cannot falsely appear healthy.

## Logging and residual risk

Existing provider HTTP factories avoid global body-logging hooks. Safe Go logs contain correlation ID, operation, status and duration, never source text or credentials. Gateway access logs omit query strings, bodies and headers. Laravel invitation/verification failures log identifiers only. Production mail must not use the log transport, which can expose verification links/content. Do not enable request-body/APM capture, SQL bindings or full error traces around integration operations. Proxy/operator logs and third-party observability need independent configuration review.

Image bases are pinned and production images separate development tooling from runtime where possible. An OS-package/container CVE scan, independent penetration test, backup-key custody, data-processing approval, production ClamAV/PDF sandbox verification, effective operational alerts and live-provider acceptance remain release gates. No zero-risk or full-compliance claim follows from automated tests.
