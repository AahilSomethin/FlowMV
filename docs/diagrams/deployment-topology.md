# Deployment topology

## Implemented showcase launch configuration

```mermaid
flowchart LR
  Browser[Local browser] -->|localhost 3010| NativeWeb[Native Next.js]
  Browser -->|localhost 8010| NativeAPI[Native Laravel]
  subgraph Native[Owned native installation]
    NativeWeb
    NativeAPI --> NativeDB[(Loopback PostgreSQL 55490)]
    NativeWorker[Worker and scheduler] --> NativeDB
    NativeWorker --> NativeFiles[Ignored encrypted private storage]
    NativeAPI -->|localhost 8020| NativeGo[Private Go AI]
    NativeWorker --> NativeGo
  end
  Browser -->|localhost 3011| Web[Compose web]
  Browser -->|localhost 8011| API[Compose API]
  subgraph Compose[Compose installation]
    Web
    API
    subgraph Private[Private database and worker network]
      PG[(postgres service - no host port)]
      Worker[Worker] --> PG
      Scheduler[Scheduler] --> PG
      Init[Guarded initialize service] --> PG
      Go[Private Go AI - no host port]
    end
    API --> PG
    API --> Go
    API --> Files[Owned private volume]
    Worker --> Files
    Worker --> Go
  end
```

Both profiles require simulated providers and array mail. Web/API additionally join the ingress bridge for IPv4/IPv6 loopback port publication; workers, database and Go remain on the private network in Compose. Native Go listens on loopback port 8020. Container walkthrough acceptance is a separate result from configuration existing in source. See [AI processing](ai-processing.md) for the trust boundary.

## Proposed production components — not deployed or verified

```mermaid
flowchart LR
  User[Browser] --> TLS[Proposed TLS reverse proxy]
  TLS --> Next[Next.js production server]
  TLS --> Laravel[Supervised Laravel application]
  Laravel --> DB[(Restricted PostgreSQL runtime role)]
  Laravel --> Secrets[Protected secret management]
  Laravel --> GoProd[Private Go AI with TLS]
  Workers[Supervised workers and scheduler] --> DB
  Workers --> Private[Encrypted private storage]
  Workers --> GoProd
  Workers --> Scanner[Verified scanner and isolated PDF runtime]
  Backup[Protected backup and recovery system] --> DB
  Backup --> Private
```

Production network, runtime grants, secret custody, backups, scanner isolation and supervision require independent validation and deployment approval.
