# Architecture and compatibility

FlowMV uses a stateful Next.js client and Laravel API. Sanctum authenticates browser sessions; organization membership and policies scope every protected use case. React Flow stores definitions, while Laravel normalization, graph validation and publishing establish the executable contract. UI controls are guidance, not authorization.

`CreateWorkflow` creates a draft; `SaveWorkflowDraft` uses revision checks; `PublishWorkflow` creates immutable `WorkflowVersion` records and pins the node-registry version. `StartWorkflowInstance` pins that version and records an initial submission snapshot. `WorkflowEngine` advances automatic nodes and creates human tasks, revisions or delays. Task actions lock and recheck state, write decision snapshots and append events. Existing V1/V2 workflows remain executable; V3 distinguishes external triggers and actions. Phase 10 adds no registry version and never rewrites published JSON.

`PublishedGraph`, `ConditionEvaluator`, `FormSubmissionValidator` and node-specific services enforce graph and input bounds. The builder retains search, tidy layout, undo/redo and unsaved-change protection. Mobile viewing must not suggest that a narrow canvas offers full desktop authoring.

## Templates and preview

`WorkflowTemplates` contains catalog version 1 and ten recipes. Provider templates require real approved connection references, authorized destinations and document schemas before draft creation. Instantiation calls existing creation/draft services in a transaction. It never publishes, grants credentials or activates subscriptions. Each new draft needs its own grants and administrator publication.

`WorkflowPreview` is a separate hypothetical evaluator. It shares pure normalization, validation, conditions and message filtering; it does not invoke `WorkflowEngine` or its execution handlers. It performs read-only grant checks and returns a definition fingerprint, safe summaries, selected branches, skipped nodes and blocked/unavailable/incomplete findings. It cannot decrypt, dispatch work or write domain records. Explicit synthetic decisions pause or advance hypothetical human nodes. A maximum of 320 transitions and a five-second evaluation deadline prevent unbounded traces; unsupported behavior is unavailable. Preview does not promise actual delivery or human eligibility.

## Integrations and event processing

Credential metadata and ciphertext are separate. Connector services define credential/configuration schemas, operations and verification. Provider-specific authorization restricts operations and destinations. Webhook ingress verifies authenticity and deduplicates events before recording durable activation work. Subscription records pin workflow versions. External identity linking or authenticated intake confirmation is required before internal actions; incoming message content is not authority.

Execution enqueues encrypted action payloads. Workers recheck current organization membership, owner/grantor permissions, connection status, credential generation, operation and destination before transport. Bounded attempts, timeouts and leases support recovery. A provider acceptance means the provider accepted a request; it is not proof of final delivery. Telegram cannot promise exactly-once delivery.

## Decisions

Laravel remains the authoritative engine and authorization boundary. Department names are demo metadata only. Application lockfiles receive verified security patches; the unused generator CLI was removed while its required CSS/license were preserved. Documentation tooling is development-only. Phase 10 added nullable evidence provenance and demo ownership; Phase 11 added AI tables. Phase 12 adds no migration and backfills no historical evidence. Native and container demo targets are separate installations. A Go service is gated by [measured CPU and end-to-end evidence](../performance/go-evaluation.md), not language preference.

## Phase 11 advisory processing

[Private Go architecture](go-service.md) owns new AI analysis. Laravel retains authorization, vault custody and decisions. V4 adds seven AI node types; V1/V2/V3 published snapshots retain their contracts. JSONB-safe canonical fingerprints bind queued configuration. [AI diagrams](../diagrams/ai-processing.md) describe the new subsystem.

## Phase 12 release candidate

[Bounded intent navigation](../performance/frontend-loading-and-prefetch.md) warms route shells without private data caching. [Candidate operations](../operations/production-readiness-checklist.md) separate PHP-FPM/TLS/restricted-role staging from the showcases. Production configuration fails closed; live and external operational gates remain pending.
