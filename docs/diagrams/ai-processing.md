# AI trust boundary and processing

```mermaid
flowchart TB
  Browser[Authenticated browser] -->|Sanctum session and CSRF| Laravel[Laravel policy and source authorization]
  Laravel --> Vault[Context-bound credential vault]
  Laravel --> Budget[Serialized UTC-day reservations]
  Laravel -->|IDs only| Queue[Durable AI queue]
  Queue --> Recheck[Recheck membership grant status source and generations]
  Recheck -->|Signed bounded request over private TLS| Go[Go AI service]
  Go -->|Fixed host structured output no tools| OpenAI[OpenAI Responses API]
  Go -->|Fixed host structured output no tools| Gemini[Gemini Interactions API]
  Go -->|Validated advisory result| Validate[Laravel reauthorization and validation]
  Validate --> Encrypted[Encrypted expiring advice]
  Validate --> Branch[Version-pinned configured outcome]
  Branch --> Human[Existing approval or bounded revision]
  Human --> Audit[Immutable decision snapshot and audit]
  Human --> Notify[Existing scoped provider outboxes]
```

Go receives no database access. Dashed production deployment components are documented separately; this diagram describes the implemented boundary. Demo provider responses and scanners are simulated.

```mermaid
sequenceDiagram
  actor Employee
  participant Email as Verified email intake
  participant Laravel
  participant Go
  participant Manager
  participant Telegram
  Employee->>Email: Synthetic message and DOCX
  Email->>Laravel: Authenticate confirmation and clean extracted evidence
  Laravel->>Go: Approved minimized content and schema
  Go-->>Laravel: Missing-field advice and unsent draft
  Laravel->>Employee: Authenticated bounded revision task
  Employee->>Laravel: Correct form through FlowMV
  Laravel->>Go: Revalidate current authorized source
  Go-->>Laravel: Complete advisory schema
  Laravel->>Telegram: Scoped private notification and FlowMV evidence link
  Telegram->>Manager: Expiring linked decision controls
  Manager->>Laravel: Verified eligible decision
  Laravel->>Laravel: One decision and immutable snapshot
  Laravel->>Email: Existing approved outcome template
```

The composed rehearsal exercises this sequence on both owned native and Docker installations, including three AI analyses, a fixed correction email and fixed employee/HR outcome emails. Domain processing and DOCX extraction actually run; provider and scanner responses are simulated. No live-provider chain has been verified.

```mermaid
erDiagram
  organizations ||--o| ai_policies : controls
  connections ||--o| ai_connections : configures
  credential_secrets }o--|| connections : encrypts
  workflows ||--o{ ai_workflow_grants : permits
  connections ||--o{ ai_workflow_grants : scopes
  workflow_instances ||--o{ ai_processing_runs : references
  ai_processing_runs ||--|| ai_usage_reservations : reserves
  organizations ||--o{ ai_demo_scenarios : owns
  ai_policies {
    bigint organization_id PK
    boolean enabled
    json providers
    json data_categories
    integer generation
    integer retention_days
  }
  ai_connections {
    uuid connection_id PK
    string model
    json operations
    bigint approved_by
    boolean approval_requested
    integer generation
  }
  ai_workflow_grants {
    bigint id PK
    uuid connection_id FK
    bigint workflow_id FK
    string operation
    bigint authorized_by
  }
  ai_processing_runs {
    uuid id PK
    bigint organization_id FK
    bigint actor_user_id FK
    uuid connection_id FK
    bigint workflow_id FK
    bigint workflow_instance_id FK
    string node_id
    string deduplication_key UK
    string status
    string provenance
    string key_version
    text ciphertext
    timestamp expires_at
  }
  ai_usage_reservations {
    uuid run_id PK
    date utc_day
    integer reserved_units
    string state
  }
```

```mermaid
flowchart TB
  subgraph Host[Implemented owned local demo]
    NativeBrowser[Browser loopback 3010] --> NativeWeb[Native Next.js]
    NativeWeb --> NativePHP[Native Laravel loopback 8010]
    NativePHP --> NativeGo[Native Go loopback 8020]
    NativePHP --> NativePG[Owned local PostgreSQL 55490]
  end
  subgraph Compose[Implemented Docker Compose]
    DockerBrowser[Browser loopback 3011] --> Web[Next.js web]
    Web --> API[Laravel API loopback 8011]
    API --> PG[Private PostgreSQL owned volume]
    Worker[Private worker and scheduler] --> PG
    API --> AIGo[Private Go no public port]
    Worker --> AIGo
  end
  Production[Proposed production supervision TLS and secret distribution] -.-> Compose
```
