# FlowMV documentation

Five primary guides cover day-to-day reading. Specialist references and historical evidence stay available when you need depth.

## Primary guides

1. [Quick start](quick-start.md) — run native or Docker, configure env, run tests
2. [Architecture](architecture.md) — system overview with embedded diagrams
3. [Workflows & integrations](workflows-and-integrations.md) — builder, providers, AI flows
4. [Operations & security](operations-and-security.md) — trust boundaries, ops, production blockers
5. [Showcase guide](showcase-guide.md) — MINDCo demo walkthrough and script

## Specialist reference

| Topic | Location |
| --- | --- |
| Detailed runbooks and candidate checklist | [operations/](operations/runbooks.md) |
| REST API contracts and routes | [api/](api/README.md) |
| AI connection setup | [integrations/ai.md](integrations/ai.md) |
| Database roles | [database-access.md](database-access.md) |
| Cache and rate limits | [cache-and-rate-limits.md](cache-and-rate-limits.md) |
| Testing commands | [testing/testing-strategy.md](testing/testing-strategy.md) |
| Known gates | [reference/outstanding-work.md](reference/outstanding-work.md) |
| Historical acceptance evidence | [archive/](archive/README.md) |

## Maintaining docs

Update the relevant primary guide when behavior changes. Keep detailed commands in specialist runbooks when brevity would drop a safety step. Validate with:

```powershell
npm ci --prefix tools/docs
node scripts/check-docs.mjs
node scripts/render-diagrams.mjs
```

Never copy tokens, login manifests or private message bodies into documentation. Showcase and production claims must stay separate.
