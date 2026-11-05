# Intent navigation, release candidate and recovery

```mermaid
sequenceDiagram
  actor Member
  participant UI as Authenticated workspace
  participant Next as Next route cache
  participant API as Laravel private API
  Member->>UI: Focus or deliberate hover
  UI->>UI: Safe path network hints scoped budget
  UI->>Next: Prefetch shell only
  Note over Next,API: No private API data<br/>or secrets prefetched
  Member->>UI: Navigate
  Next-->>UI: Reuse route shell
  UI->>API: Fetch current data with session
  API->>API: Recheck organization membership and visibility
  API-->>UI: Private no-store response
  Note over UI: Logout or scope change clears metadata and remounts private views
```

```mermaid
flowchart TB
  Browser[HTTPS browser] --> Gateway[Loopback TLS gateway]
  subgraph Candidate[Implemented isolated release candidate]
    Gateway --> Web[Next production runtime]
    Gateway --> FPM[Laravel PHP FPM]
    FPM --> PG[Private PostgreSQL runtime role]
    Worker[Supervised worker] --> PG
    Scheduler[Supervised scheduler] --> PG
    Worker --> Go[Private TLS Go]
    FPM --> Go
    FPM --> Files[Encrypted private volume]
    Worker --> Files
    Migrator[Explicit operations profile migrator] --> PG
  end
  PKI[Operator public PKI secret custody and DB TLS] -.-> Candidate
  Egress[Pending controlled provider HTTPS egress] -.-> Go
  Scanners[Pending verified scanner and PDF namespace] -.-> Worker
```

```mermaid
flowchart TB
  Guard[Verify exact staging owner and installation] --> Pause[Quiesce staging API worker scheduler]
  Pause --> Dump[Custom PostgreSQL backup in private storage]
  Dump --> Restore[Restore into separate owned database]
  Restore --> Compare[Compare 83 table inventories and data checksums]
  Compare --> Resume[Restart original staging services]
  Resume --> Health[Verify current worker and scheduler heartbeats]
  External[Pending encrypted file key offsite and PITR rehearsal] -.-> Restore
```

Solid paths are implemented local staging behavior; dashed paths are external production gates. The rehearsal never restores over the source or showcase databases. See the [readiness checklist](../operations/production-readiness-checklist.md). Engineering acceptance evidence for these diagrams is recorded in the [final acceptance ledger](../archive/phase12-final-acceptance.md).
