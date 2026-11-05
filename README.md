# FlowMV

FlowMV is a workplace request and approval application: Next.js and React Flow provide the authoring interface; Laravel executes immutable published workflows with organization permissions, decision snapshots and durable integration outboxes. PostgreSQL is the application database. A private Go service now owns optional advisory AI processing; Laravel retains authorization and workflow decisions.

**Maturity:** Phase 12 engineering is accepted for the verified local/simulated scope; production is **NOT CLEARED**. Native and Docker showcases and isolated staging were exercised separately. Live-provider verification, production operations and independent accessibility/security gates remain open in the [current acceptance report](docs/phase12-final-acceptance.md). Never treat a simulated response as real message delivery. Historical phase reports retain their original scope.

Start with the [developer documentation](docs/README.md) or the [isolated showcase setup](docs/demo/demo-setup.md). No real provider credentials are required for the showcase.

## Local showcase

Prerequisites: PHP 8.4 with PostgreSQL, ZIP, XML/XMLReader and mbstring extensions; Composer; Node 22; PostgreSQL 18 tools; Go 1.27.3 (the module pins the toolchain). From the repository root:

```powershell
composer install --working-dir=backend
npm ci --prefix frontend
node scripts/demo-setup.mjs native
node scripts/demo-run.mjs
```

Open `http://localhost:3010`. Generated login details are in the ignored `backend/storage/app/phase10-demo/native/accounts.json`. Use ordinary login/logout to change roles. Treat this private file like passwords. The launcher owns only its child processes; Ctrl+C stops them. It leaves PostgreSQL and data intact. Setup refuses existing QA targets and never resets a database.

## Docker showcase

Docker Desktop must have a working Linux engine:

```powershell
node scripts/demo-container.mjs up
node scripts/demo-container.mjs verify
node scripts/demo-container.mjs stop
```

Open `http://localhost:3011`. Compose uses a private network and named volumes; PostgreSQL has no exposed port. See [setup and evidence limitations](docs/demo/demo-setup.md) before claiming container acceptance. No destructive reset command is provided.

## Repository

| Path | Purpose |
| --- | --- |
| `backend/app/Actions` | Transactional workflow and organization use cases |
| `backend/app/Services/Workflows` | Versioned definitions, pure validation, preview and execution |
| `backend/app/Services/Integrations` | Vault, scoped connectors, verified ingress and durable outboxes |
| `frontend/components` | Authenticated product screens, builder and provider setup |
| `scripts`, `docker/demo` | Owned demonstration launchers and container definitions |
| `docs` | Architecture, APIs, security, operations and acceptance evidence |

See [testing](docs/testing/testing-strategy.md) for sequential checks and the guarded PostgreSQL test target. Never run `migrate:fresh`, reset hosted data or place production tokens in fixtures. Preserve V1/V2/V3 published contracts.

## Phase 11 advisory AI

See [implementation and acceptance](docs/phase11-ai-go-acceptance.md), [OpenAI/Gemini website setup](docs/integrations/ai.md), and [Go migration measurements](docs/performance/go-migration-results.md). After launching the guarded demo, run `php artisan demo:ai-seed --env=demo` from backend; repeat runs verify without overwriting. Live-provider verification remains pending.

## Licensing

No project license has been established in this repository. Framework and dependency licenses do not grant a license to FlowMV application code. Obtain the owner's explicit terms before redistribution; dependency notices remain applicable.

Phase 12 engineering and release gates are tracked in the [current acceptance report](docs/phase12-final-acceptance.md). The isolated [release candidate](docs/operations/production-readiness-checklist.md) is separate from the showcases; production launch remains NOT CLEARED.
