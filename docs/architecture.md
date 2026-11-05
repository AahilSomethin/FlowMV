# Architecture

How FlowMV fits together: Next.js UI, Laravel authorization and workflow engine, private Go advisory AI, PostgreSQL, workers and external providers.

**Status:** Implemented for local/simulated showcases. Production deployment is **not cleared**.

## System overview

Authenticated members use the browser only. Laravel owns sessions, policies, workflow decisions and vault custody. Go receives signed ID-scoped requests and never talks to the browser or PostgreSQL.

```mermaid
flowchart LR
  subgraph People[Authenticated workplace members]
    E[Employee]
    M[Manager]
    A[Administrator]
  end
  subgraph App[FlowMV trust boundary]
    UI[Next.js frontend]
    API[Laravel API and policies]
    DB[(PostgreSQL)]
    W[Queue workers]
    S[Scheduler]
    V[CredentialVault]
    F[(Encrypted private documents)]
    K[Separately managed vault keys]
    Go[Private Go advisory AI]
    UI -->|Session cookie and CSRF| API
    API --> DB
    API --> V
    W -->|Runtime permission checks| API
    W --> DB
    W --> V
    S -->|Recovery jobs| W
    V --> K
    API --> F
    W --> F
    API -->|Signed IDs only| Go
    W -->|Signed IDs only| Go
  end
  E --> UI
  M --> UI
  A --> UI
  P[Telegram / email / Slack / SMS]
  AI[OpenAI / Gemini]
  P -->|Verified bounded ingress| API
  W -->|Approved operation and destination| P
  Go -->|Fixed-host structured output| AI
```

## Component responsibilities

| Component | Owns |
| --- | --- |
| Next.js / React Flow | Auth shell, builder, Connections UI, tasks/requests/analytics |
| Laravel | Sanctum, RBAC, draft/publish, engine, vault, outboxes, audit |
| Go | Advisory analysis, structured output, prompt isolation, concurrency limits |
| PostgreSQL | Tenants, versions, instances, tasks, events, grants |
| Workers / scheduler | Activation, delivery, AI jobs, recovery |

```mermaid
flowchart TB
  subgraph Frontend[Next.js and React]
    Shell[Auth and workspace shell]
    Builder[React Flow builder]
    Views[Tasks / requests / analytics / Connections]
    PreviewUI[Hypothetical preview]
  end
  subgraph Laravel[Laravel application]
    Routes[API controllers]
    Policies[Membership / policies / step-up]
    Actions[Create / save / publish / decide]
    Registry[NodeRegistry V1-V4]
    Engine[WorkflowEngine]
    Vault[CredentialVault]
    Jobs[Activation / delivery / AI jobs]
  end
  Go[Private Go AI]
  Store[(PostgreSQL and private files)]
  Shell --> Routes
  Builder --> Routes
  Views --> Routes
  PreviewUI --> Routes
  Routes --> Policies
  Routes --> Actions
  Actions --> Registry
  Actions --> Engine
  Engine --> Jobs
  Jobs --> Vault
  Jobs -->|Signed IDs only| Go
  Actions --> Store
  Vault --> Store
```

Preview never calls jobs, vault or Go. Specialist detail: [architecture/go-service.md](architecture/go-service.md), [api/README.md](api/README.md).

## Authentication and permissions

1. Browser obtains CSRF cookie, then logs in with email/password over Sanctum.
2. Organization membership scopes every protected UUID.
3. Policies authorize Admin / Manager / Employee actions.
4. Sensitive connection changes require password step-up (HTTP 428 if stale).
5. Provider use needs Admin approval, destination approval and per-workflow operation grants.
6. Workers recheck membership, grant, generation and destination before transport.

Personal credentials stay hidden from other members, including Admins, on the connection overview.

## Workflow execution

Publication creates an immutable `WorkflowVersion` and pins a NodeRegistry version. Runtime pins that version for every request. Human decisions write decision snapshots and append-only events.

```mermaid
flowchart TD
  Trigger[Form or verified external activation] --> Gate[Membership and activation authority]
  Gate --> Pin[Pin immutable published version]
  Pin --> Inputs[Validate inputs and initial snapshot]
  Inputs --> Run[Advance automatic nodes]
  Run --> Kind{Node type}
  Kind -->|Condition| Branch[Pure rule evaluation]
  Branch --> Run
  Kind -->|Approval| Task[Eligible human task]
  Task --> Decision[Locked decision and permission check]
  Decision --> Snapshot[Decision snapshot and event]
  Snapshot --> Run
  Kind -->|Revision| Rev[Submitter correction]
  Rev --> Correct[Authenticated correction snapshot]
  Correct --> Run
  Kind -->|Delay| Due[Durable due record]
  Due --> Run
  Kind -->|External action| Outbox[Encrypted action outbox]
  Outbox --> Run
  Kind -->|Advisory AI| AiQueue[Signed Go analysis by IDs]
  AiQueue --> AiResult[Laravel validates advice]
  AiResult --> Run
  Kind -->|End| End[Terminal outcome]
```

Provider acceptance is not recipient delivery. AI advice never replaces a human decision.

## Database relationships (high level)

Major entities only — not a column-complete schema.

```mermaid
erDiagram
  direction LR
  users ||--o{ organization_memberships : member
  organizations ||--o{ organization_memberships : scopes
  organizations ||--o{ workflows : owns
  workflows ||--|| workflow_drafts : mutable_draft
  workflows ||--o{ workflow_versions : publishes
  workflow_versions ||--o{ workflow_instances : pins
  workflow_instances ||--o{ workflow_tasks : assigns
  workflow_instances ||--o{ workflow_events : records
  workflow_instances ||--o{ workflow_submission_snapshots : captures
  organizations ||--o{ connections : scopes
  connections ||--o| credential_secrets : encrypted
  connections ||--o{ workflow_connection_grants : permits
  workflows ||--o{ workflow_connection_grants : uses
  workflow_instances ||--o{ ai_processing_runs : references
```

Subsystem ERDs (email, Slack, SMS, AI attributes): [diagrams/database-erd.md](diagrams/database-erd.md).

## Go AI processing

Laravel authorizes sources and budgets, queues ID-only jobs, then calls Go over signed private HTTP. Go talks only to fixed-host OpenAI/Gemini APIs with structured output and no tools. Laravel revalidates results before branching.

```mermaid
flowchart TB
  Browser[Authenticated browser] -->|Sanctum and CSRF| Laravel[Laravel policy and source authorization]
  Laravel --> Vault[Credential vault]
  Laravel --> Budget[UTC-day reservations]
  Laravel -->|IDs only| Queue[Durable AI queue]
  Queue --> Recheck[Recheck membership grant source generations]
  Recheck -->|Signed private request| Go[Go AI service]
  Go -->|Structured output no tools| OpenAI[OpenAI]
  Go -->|Structured output no tools| Gemini[Gemini]
  Go -->|Validated advice| Validate[Laravel reauthorization]
  Validate --> Encrypted[Encrypted expiring advice]
  Validate --> Branch[Version-pinned outcome]
  Branch --> Human[Approval or revision]
  Human --> Audit[Immutable snapshot and audit]
```

Showcase AI responses are simulated. Live-provider quality and billing remain unverified.

## Deployment architecture

### Implemented showcases

```mermaid
flowchart LR
  Browser[Local browser] -->|3010| NativeWeb[Native Next.js]
  Browser -->|8010| NativeAPI[Native Laravel]
  subgraph Native[Owned native]
    NativeWeb
    NativeAPI --> NativeDB[(Loopback PG 55490)]
    NativeWorker[Worker and scheduler] --> NativeDB
    NativeAPI -->|8020| NativeGo[Private Go]
    NativeWorker --> NativeGo
  end
  Browser -->|3011| Web[Compose web]
  Browser -->|8011| API[Compose API]
  subgraph Compose[Compose]
    Web
    API
    subgraph PrivateNet[Private network]
      PG[(postgres - no host port)]
      Worker --> PG
      Scheduler --> PG
      GoSvc[Private Go]
    end
    API --> PG
    API --> GoSvc
    Worker --> GoSvc
  end
```

### Proposed production (not deployed)

HTTPS reverse proxy, supervised PHP-FPM, restricted DB runtime role, private Go TLS, verified scanners and backup systems remain gates. See [operations-and-security.md](operations-and-security.md) and [operations/production-readiness-checklist.md](operations/production-readiness-checklist.md).
