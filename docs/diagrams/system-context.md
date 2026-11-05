# System context

Derived from session middleware, AppServiceProvider, queue jobs, Compose and the private Go advisory service.

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
  P[Telegram / email / Slack / SMS providers]
  AI[OpenAI / Gemini]
  P -->|Verified bounded ingress| API
  W -->|Approved operation and destination| P
  Go -->|Fixed-host structured output| AI
```

Laravel remains the authorization and decision boundary. The browser never calls Go. The demo substitutes guarded synthetic transports and scanner responses. No production trust is established by the diagram.
