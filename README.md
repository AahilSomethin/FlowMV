# FlowMV

FlowMV is a workplace request and approval platform. Teams design workflows in a visual builder, employees submit requests, managers decide, and integrations deliver outcomes with organization-scoped permissions and immutable audit history.

## Capabilities

- Visual workflow builder with drafts and immutable published versions
- Approvals, revisions, conditions, delays and multi-step processes
- Email, documents, Slack, Telegram and SMS connectors
- Optional advisory AI via Gemini and OpenAI (private Go service)
- Organization RBAC, encrypted credentials and private document storage
- Analytics and append-only audit history

## Stack

| Layer | Technology |
| --- | --- |
| Frontend | Next.js, React, React Flow |
| Application API | Laravel (PHP), Sanctum sessions |
| Advisory AI | Private Go REST service |
| Database | PostgreSQL |
| Workers | Laravel queue + scheduler |

**Showcase vs production:** Native and Docker showcases are verified with **simulated** provider and AI transports. Production is **NOT CLEARED**. See [outstanding work](docs/reference/outstanding-work.md).

## Native quick start

Prerequisites: PHP 8.4 (PostgreSQL, ZIP, XML/XMLReader, mbstring), Composer, Node 22, PostgreSQL 18 tools, Go 1.27.3.

```powershell
composer install --working-dir=backend
npm ci --prefix frontend
node scripts/demo-setup.mjs native
node scripts/demo-run.mjs
```

Open `http://localhost:3010`. Passwords are only in the ignored file `backend/storage/app/phase10-demo/native/accounts.json`.

## Docker quick start

```powershell
node scripts/demo-container.mjs up
node scripts/demo-container.mjs verify
node scripts/demo-container.mjs stop
```

Open `http://localhost:3011`. PostgreSQL has no host port. No destructive reset command is provided.

## Testing overview

```powershell
npm test --prefix frontend -- --maxWorkers=2
npm run typecheck --prefix frontend
npm run lint --prefix frontend
php artisan test --compact   # from backend/
go test ./...                # from services/ai-go/
```

Never run `migrate:fresh` on demo, QA or hosted databases.

## Documentation

| Guide | Purpose |
| --- | --- |
| [Quick start](docs/quick-start.md) | Prerequisites, env, native/Docker, tests |
| [Architecture](docs/architecture.md) | System diagrams, auth, Go AI, deployment |
| [Workflows & integrations](docs/workflows-and-integrations.md) | Builder, registry, providers, AI flows |
| [Operations & security](docs/operations-and-security.md) | Trust boundaries, runbooks, blockers |
| [Showcase guide](docs/showcase-guide.md) | MINDCo demo script and scenarios |

Full index: [docs/README.md](docs/README.md). Historical evidence: [docs/archive](docs/archive/README.md).

## Licensing

No project license is established. Dependency licenses are not a substitute for FlowMV application terms.
