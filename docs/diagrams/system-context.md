# System context

Derived from session middleware, AppServiceProvider, queue jobs and Compose.

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
  end
  E --> UI
  M --> UI
  A --> UI
  P[Telegram / email / Slack / SMS providers]
  P -->|Verified bounded ingress| API
  W -->|Approved operation and destination| P
```

The demo substitutes guarded synthetic transports and scanner responses. No production trust is established by the diagram.
