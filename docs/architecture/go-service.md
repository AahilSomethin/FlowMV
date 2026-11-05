# Go service and migration boundary

Phase 11 introduces `services/ai-go`, a Go 1.27.3 standard-library service with transport, security, contracts, AI, document and provider packages. It owns new advisory analysis, provider-specific structured-output requests, prompt isolation, output validation, concurrency limits and safe failure handling. It is not a forwarding facade around Laravel.

Laravel retains authentication, organization/member authorization, vault decryption, approved data sources, usage reservations, publication, version pinning, state transitions, human approvals, snapshots and authoritative audit. The browser never calls Go. Go receives neither PostgreSQL credentials nor permission to change domain state, send notifications, create grants or invoke model tools.

## Migration audit

| Existing candidate | Decision and evidence |
| --- | --- |
| Event normalization/validation | Keep signed ingress in Laravel. Provider verification, identity and deduplication are already coupled to scoped activation. No equivalent migration benefit demonstrated. |
| Deterministic document extraction | Keep scanning, ZIP/XML parsing and PDF safeguards in Laravel. A pure extracted-text matcher was prototyped in Go and compared with PHP over authenticated HTTP; parity held but communication cost dominated. Retain PHP. |
| Integration event processing | Keep activation and outboxes in Laravel; interpret authorized email/Slack/document content in the new Go AI service. |
| Analytics aggregation | Retain SQL and Laravel visibility. Phase 10 measurements showed SQL/state coupling, not an eligible pure CPU bottleneck. |
| Read-only domain REST APIs | Retain authorization, pagination and visibility contracts in Laravel. A forwarding layer adds cost without meaningful ownership. |
| AI orchestration | Implement in Go as new functionality, not a claimed optimization of prior PHP work. |
| Provider-independent text processing | The pure matching prototype remains internal for parity/measurement; production document execution still uses PHP. |

See [measurements](../performance/go-migration-results.md). No migration claims are made from isolated microbenchmarks or the native/container performance gap.

## Internal REST contract

`flowmv.ai.v1` exposes `GET /healthz`, `GET /readyz`, and signed JSON `POST /v1/` operations: `analyze/email`, `analyze/document`, `analyze/slack`, `summarize`, `classify`, `extract/fields`, `draft/reply`, `connections/test`, and experimental `extract/deterministic`.

The exact signature bytes are `METHOD + newline + PATH + newline + timestamp + newline + nonce + newline + boot_audience + newline + sha256(body)`, HMAC-SHA256 with a separate 32-byte service key. Headers are `X-FlowMV-Time`, `X-FlowMV-Nonce`, `X-FlowMV-Audience`, `X-FlowMV-Signature`. Readiness returns a random boot audience, not the key. Timestamp tolerance is 30 seconds old / five seconds future. A bounded 10,000-entry nonce cache rejects replay; a restart changes the audience and invalidates old signatures. Clocks must be synchronized. Signature scope never delegates domain authorization to the model.

JSON rejects unknown/duplicate keys, trailing values, invalid UTF-8 and excessive nesting. Request bodies are capped at 256 KiB; text at 100,000 bytes; structured fields at 20; category/route options at 16; output at 4,096 configured tokens. Responses contain validated advice, requested model, prompt version, provenance and reported token counts. An unresolved provider model remains null in Laravel; requested model is not presented as a resolved version. No model confidence score is invented.

Four calls may run concurrently; the service admits 120 signed calls per minute, with Laravel organization/request limits and usage reservations in addition. Requests have bounded connect/read/write/provider deadlines and a 25-second processing limit. There is no automatic paid retry or provider fallback. A timeout, malformed output, refusal, rate limit or ambiguous call leads to manual review. Jobs contain only IDs. Recovery marks stale processing uncertain/failed rather than automatically repeating inference.

## Provider boundary

OpenAI uses fixed-host Responses API structured JSON output with `store:false`; Gemini uses fixed-host Interactions structured output with `store:false` and an explicit API revision. Neither exposes tools, user-supplied API URLs, redirects, arbitrary code, history persistence or model-selected recipients. Metadata tests fetch only the configured model, not workplace content. Responses are bounded and independently validated again in Laravel. Logs contain correlation IDs, operation, safe status/error codes and duration; no request/provider bodies, credentials or private messages.

Credentials are transient in Laravel-to-Go and Go-to-provider requests. Go has no persistence or vault access. In-memory lifetime is bounded, but memory zeroization is not claimed for immutable strings or runtime copies. Private authenticated TLS is mandatory outside loopback/owned demo networking. Do not enable request-body or outbound-header tracing.

## Runtime configuration

| Variable | Purpose |
| --- | --- |
| `AI_ENABLED` | Laravel feature gate; false by default |
| `AI_SIMULATED` | Deterministic transports; guarded demo only |
| `AI_SERVICE_URL` | Operator-owned internal origin; no user API base URLs |
| `AI_SERVICE_KEY` | Independent 64-character hex service key shared with Go; never APP_KEY or vault key |
| `AI_LISTEN` | Go listener; loopback by default, port 8020 |
| `AI_PRIVATE_CONTAINER` | Permit the private container listener |
| `AI_DEMO_INSTALLATION`, `AI_DEMO_DATABASE` | Go simulation metadata; Laravel separately verifies actual database ownership on every guarded path |
| `AI_TLS_CERT`, `AI_TLS_KEY` | Go TLS certificate/key paths for non-demo operation |
| `GOMEMLIMIT`, `GOMAXPROCS` | Go resource limits; Compose uses 192 MiB soft memory limit and two processors |

The model allowlist is maintained in `backend/config/ai.php`. Model selection, operation allowlists and smaller caps are enforced by organization and connection policies. No unused extraction rollout flag activates a migration.

## Native and Docker

The native launcher builds the Go module, checks ports and starts only its own children. Go receives a minimal environment without DB or vault keys. Compose uses a digest-pinned multi-stage image, a static scratch runtime, UID 65532, read-only filesystem, no capabilities, private network, healthcheck, resource limits and a 35-second shutdown grace period. It has no public port or internet route in the simulated demo network. The Go QA image alone enables CGO for race tests.

Sources checked 2026-11-04: [OpenAI structured output](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses), [model metadata](https://developers.openai.com/api/reference/resources/models/methods/retrieve), [Gemini structured output](https://ai.google.dev/gemini-api/docs/structured-output), [Gemini model metadata](https://ai.google.dev/api/models), [Go release manifest](https://go.dev/dl/?mode=json). These contracts do not constitute live-provider acceptance.

## Phase 12 lifecycle hardening

Healthcheck honors the configured port and verifies TLS, including its loopback SAN; a partial TLS key/certificate pair fails startup. On termination, readiness drains first and main waits for graceful shutdown to finish instead of exiting as soon as the listener closes. A 30-second drain is bounded and forcibly closes remaining connections at expiry. Tests hold an active request through listener shutdown and verify completion. Private CA support is operator-configured in Laravel through `AI_CA_CERT`, never disabled verification. No additional Laravel domain responsibility migrated to Go.
