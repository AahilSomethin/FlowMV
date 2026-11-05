# FlowMV documentation

This is the current documentation entry point. Historical phase reports retain their original evidence; new guides describe current code and explicitly separate operational proposals from verified results.

| Area | Authoritative guide |
| --- | --- |
| Requirements, native setup and troubleshooting | [Getting started](getting-started/local-development.md) |
| Architecture and version compatibility | [Architecture](architecture/overview.md) |
| Source, route and Markdown inventory | [Repository inventory](architecture/repository-inventory.md) |
| Trust boundaries and permissions | [Security](architecture/security-boundaries.md) |
| Routes, payloads and errors | [API reference](api/README.md) |
| Providers, credential management and connector development | [Integrations](integrations/README.md) |
| Workers, deployment, recovery, retention and incidents | [Operations](operations/runbooks.md) |
| Backend, frontend, database and browser checks | [Testing](testing/testing-strategy.md) |
| Role-based product use | [User guides](guides/roles-and-workflows.md) |
| Page-by-page UX and security findings | [UX audit register](ux-audit.md) |
| Architecture and subsystem ERDs | [Diagrams](diagrams/README.md) |
| Reproducible demo | [Setup](demo/demo-setup.md), [presentation](demo/demo-script.md) |
| Live verification, Phase 11, Phase 12 and debt | [Outstanding work](reference/outstanding-work.md) |
| Historical reports and missing evidence | [Archive index](archive/README.md) |
| Phase 12 current engineering and release gates | [Acceptance](phase12-final-acceptance.md), [navigation](performance/frontend-loading-and-prefetch.md), [backend measurements](performance/backend-optimization.md), [security](security/final-security-review.md), [production checklist](operations/production-readiness-checklist.md) |
| Phase 11 implementation and checks | [Acceptance](phase11-ai-go-acceptance.md), [AI setup](integrations/ai.md), [Go architecture](architecture/go-service.md), [measurements](performance/go-migration-results.md) |
| Phase 10 actual checks | [Acceptance report](phase10-acceptance.md) |
| Measurements and Go gate | [Go evaluation](performance/go-evaluation.md) |

## Maintaining these guides

For a route, schema, permission or worker change, update its guide, applicable diagram and focused test together. Generate route facts with `php artisan route:list --json --except-vendor`; never copy tokens, login manifests, private documents, message bodies or raw environment files into documentation. Keep prior phase counts unchanged and add new evidence to the current ledger.

Install approved development tools with `npm ci --prefix tools/docs`. Run `node scripts/check-docs.mjs` and `node scripts/render-diagrams.mjs` from root. Mermaid CLI is pinned in its separate lockfile; it adds no application dependency. Rendered artifacts are ignored. Inspect the renders as well as checking syntax. File-link validation excludes remote URLs and anchors, which need separate review. Update acceptance with actual command, date, target, exit result and limitations. Do not label proposed procedures as recovery or deployment evidence.
