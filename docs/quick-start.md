# Quick start

Get FlowMV running locally for development or demonstration. Commands below match the repository launchers.

**Profiles:** native development · Docker demonstration · automated tests · production (not cleared)

## Prerequisites

| Tool | Notes |
| --- | --- |
| PHP 8.4 | Extensions: PDO PostgreSQL, ZIP, mbstring, XMLReader, DOM, OpenSSL |
| Composer | Backend dependencies |
| Node 22 | Frontend and scripts |
| PostgreSQL 18 tools | Native showcase cluster |
| Go 1.27.3 | Module pins toolchain; advisory AI service |
| Docker Desktop | Linux engine for Compose showcase |

Lockfiles under `backend/` and `frontend/` are authoritative.

## Repository structure

| Path | Role |
| --- | --- |
| `frontend/` | Next.js UI and workflow builder |
| `backend/` | Laravel API, engine, integrations |
| `services/ai-go/` | Private advisory AI REST service |
| `scripts/` | Demo and staging launchers |
| `docker/demo/` | Compose showcase definitions |
| `docs/` | Guides and evidence |

## Environment setup

| Use | File / source |
| --- | --- |
| Local development template | `backend/.env.example`, `frontend/.env.example` |
| Native showcase | Generated ignored `backend/.env.demo` via `demo-setup.mjs` |
| Docker showcase | Generated ignored `backend/.env.demo.container` |
| Production candidate template | `docker/production/app.env.example` |
| Integration / AI defaults | `backend/config/integrations.php`, `config/ai.php`, `config/demo.php` |

Never put vault keys or provider tokens in `NEXT_PUBLIC_*` variables. Historical QA profiles (`.env.phase85`, `.env.phase9b/c/d`) are private and unused by product launchers.

## Native showcase (recommended evaluation path)

```powershell
composer install --working-dir=backend
npm ci --prefix frontend
node scripts/demo-setup.mjs native
node scripts/demo-run.mjs
```

- UI: `http://localhost:3010` · API: `http://localhost:8010` · Go: loopback `8020`
- Accounts: ignored `backend/storage/app/phase10-demo/native/accounts.json`
- Setup creates an owned PostgreSQL cluster on `127.0.0.1:55490`, migrates forward and seeds through domain services
- Setup refuses QA/hosted targets and never resets a nonempty owned database
- Ctrl+C stops launcher children only; PostgreSQL and data remain

After a reboot, re-run `node scripts/demo-setup.mjs native` to start the existing cluster without overwriting, then `demo-run.mjs`.

### Optional AI demo seed

With the native launcher running:

```powershell
cd backend
php artisan demo:ai-seed --env=demo --no-interaction
php artisan demo:ai-seed --verify --env=demo --no-interaction
```

Repeat runs verify without overwriting. Never point this at live AI credentials.

### Production frontend bundle (native)

```powershell
$env:NEXT_PUBLIC_API_URL = 'http://localhost:8010'
$env:FLOWMV_SESSION_COOKIE = 'flowmv10-demo'
$env:FLOWMV_DIST_DIR = '.next-phase12-native-production'
npm run build --prefix frontend
node scripts/demo-run.mjs --production
```

## Docker showcase

```powershell
node scripts/demo-container.mjs up
node scripts/demo-container.mjs verify
node scripts/demo-container.mjs stop
```

- UI: `http://localhost:3011` · API: `http://localhost:8011`
- Private Compose network; PostgreSQL has no host port
- No volume-reset wrapper is provided

AI seed inside Compose:

```powershell
docker exec flowmv-phase10-demo-api-1 php artisan demo:ai-seed --no-interaction
```

## Ordinary native development (non-demo)

1. Copy `backend/.env.example` to a private `.env` and set a separate PostgreSQL database, `APP_KEY`, URLs and Sanctum domains.
2. `composer install` in `backend/`; `npm ci` in `frontend/`.
3. Apply reviewed forward migrations: `php artisan migrate` (never `migrate:fresh` on shared data).
4. Run API, `queue:work`, `schedule:work` and `npm run dev` as separate processes.

The demo launchers are preferred when evaluating integrations: a client flag alone never enables fake transports.

## Go service

Showcase launchers build and start Go with a minimal environment (no DB or vault keys). Manual development details: [architecture.md](architecture.md) and [architecture/go-service.md](architecture/go-service.md).

## Test commands

```powershell
# Frontend (from frontend/)
npm test -- --maxWorkers=2
npm run typecheck
npm run lint
npm run build

# Laravel (from backend/)
php artisan test --compact
php vendor/bin/phpunit --configuration=phpunit.pgsql.xml   # guarded loopback only
php vendor/bin/pint --dirty --format agent

# Go (from services/ai-go/)
go test ./...
go vet ./...
```

The PostgreSQL suite targets owned loopback `flowmv_phase85_test` and refuses wrong hosts. Report a skip if that server is down; do not treat SQLite as equivalent.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Login 419 / CSRF | Same hostname+port for frontend and Sanctum; CSRF cookie before login; do not disable CSRF |
| 428 on Connections | Password step-up required; confirmation expires after five minutes |
| Demo ownership refused | Exact profile, DB owner and installation marker — do not rewrite markers |
| Port in use | Launchers refuse to kill foreign processes |
| Jobs stuck | Worker and scheduler running; safe error codes; current grants |
| Scanner / PDF unavailable | Fail closed; showcase uses real DOCX parsing with simulated scanner |

More detail: [operations-and-security.md](operations-and-security.md), [showcase-guide.md](showcase-guide.md).
