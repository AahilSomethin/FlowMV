# Component architecture

```mermaid
flowchart TB
  subgraph Frontend[Next.js and React]
    Shell[Auth and workspace shell]
    Builder[React Flow builder]
    Library[Template binding wizard]
    Views[Tasks / requests / analytics / Connections]
    PreviewUI[Hypothetical preview trace]
  end
  subgraph Laravel[Laravel application]
    Routes[Stateful API controllers and resource locators]
    Policies[Membership / policies / step-up]
    Actions[Create / save / publish / submit / decide actions]
    Registry[NodeRegistry V1 / V2 / V3 / V4]
    Normalize[Definition normalization and validation]
    Engine[WorkflowEngine and PublishedGraph]
    Pure[WorkflowPreview and pure evaluators]
    Connectors[Connector interface and provider services]
    Jobs[Activation / extraction / delivery / AI jobs]
    Audit[Append-only events and decision snapshots]
    Vault[CredentialVault]
  end
  Go[Private Go advisory AI service]
  Store[(PostgreSQL and private encrypted files)]
  Shell --> Routes
  Builder --> Routes
  Library --> Routes
  Views --> Routes
  PreviewUI --> Routes
  Routes --> Policies
  Routes --> Actions
  Routes --> Pure
  Actions --> Normalize
  Normalize --> Registry
  Actions --> Engine
  Engine --> Registry
  Engine --> Connectors
  Engine --> Audit
  Connectors --> Jobs
  Jobs --> Policies
  Jobs --> Vault
  Jobs -->|Signed IDs only| Go
  Audit --> Store
  Actions --> Store
  Vault --> Store
  Pure -->|Read-only grants| Store
  Pure --> Normalize
```

NodeRegistry V4 covers seven advisory AI node types only; V1/V2/V3 published snapshots retain their contracts. Preview has no edge to jobs, vault, Go or execution handlers. Provider implementations retain their specific authorization boundaries.
