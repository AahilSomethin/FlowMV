# Repository cleanup report

Date: 2026-11-05. Scope: final product cleanup for developer handoff and portfolio presentation. No commits, pushes, hosted resets, volume deletes, secret rotation, reseeds, schema migrations, live provider messages or paid AI calls.

## Initial findings

- Product UI navigation, page titles and API errors were already capability-named. No sidebar “Phase” labels.
- Phase terminology concentrated in active documentation headings, one diagram filename (`phase12-operations.md`), and developer/QA identifiers.
- Historical acceptance reports at `docs/phase*.md` were already indexed by [archive/README.md](../archive/README.md) without moving files.
- Prior generated-build cleanup ([repository housekeeping](../operations/repository-housekeeping.md), `scripts/cleanup-artifacts.ps1`) was complete; this pass did not repeat bulk `.next-*` deletion.
- Confirmed dead frontend file: `frontend/components/modules/module-placeholder.tsx` (zero imports).
- Future AI seed copy used connection name `MINDCo Phase 11 simulated mailbox` in `AiShowcaseDataset.php`.
- Existing demo database rows still show historical labels such as `MINDCo Phase 11 simulated mailbox`, `Phase 10 lifecycle rehearsal`, and workflow `Phase 10 browser rehearsal`. Those records were deliberately left unchanged.

## Code changes

| Change | Detail |
| --- | --- |
| Removed dead component | Deleted unused `module-placeholder.tsx` and empty `components/modules/` |
| Future seed label | `AiShowcaseDataset` connection name → `MINDCo simulated mailbox` (affects new `demo:ai-seed` runs only) |
| Env example comments | [backend/.env.example](../../backend/.env.example) and [frontend/.env.example](../../frontend/.env.example) document local vs showcase vs production templates; removed “Phase 6” wording from cache comment |

## Dead code removed

- `frontend/components/modules/module-placeholder.tsx`

No Laravel/Go dead packages were removed. Compatibility, NodeRegistry V1–V4, and phase-named regression tests were retained.

## Naming changes

| Before | After | Surface |
| --- | --- | --- |
| Phase-framed README / docs index rows | Capability-based documentation map | Root README, `docs/README.md` |
| “Phase 11 advisory AI”, “Phase 12 …” section titles | Advisory AI, release candidate, engineering ledger | Active architecture, API, integrations, operations, testing, demo, performance, UX, security guides |
| `docs/diagrams/phase12-operations.md` | `docs/diagrams/release-candidate-operations.md` | Diagram catalog |
| `MINDCo Phase 11 simulated mailbox` (seed source) | `MINDCo simulated mailbox` | Future AI showcase seed only |

Identifiers intentionally unchanged: `APP_ENV` values `phase85` / `phase9b` / `phase9c` / `phase9d`, database and Compose names (`flowmv_phase10_demo`, `flowmv-phase10-demo`), migration filenames, `Phase7AutomationNodesTest`, `Phase85NotificationReliabilityTest`, staging smoke org `Phase 12 Isolated Staging`, NodeRegistry constants, published workflow snapshots.

## Documentation files updated

- [README.md](../../README.md)
- [docs/README.md](../README.md)
- [docs/archive/README.md](../archive/README.md)
- Architecture: overview, go-service, repository-inventory, security-boundaries
- API: `api/README.md`
- Integrations: `integrations/README.md`
- Operations: `operations/runbooks.md`
- Testing: `testing/testing-strategy.md`
- Demo: `demo-setup.md`, `demo-script.md`
- Getting started: `local-development.md`
- Performance: frontend-loading, backend-optimization, go-migration-results
- UX / security / ops refs: `ux-audit.md`, `security/final-security-review.md`, `cache-and-rate-limits.md`, `database-access.md`, `reference/outstanding-work.md`
- Package READMEs: `frontend/README.md`, `backend/README.md`

## Documentation files added

| File | Purpose |
| --- | --- |
| [docs/getting-started/docker.md](../getting-started/docker.md) | Docker showcase entry |
| [docs/workflows/README.md](../workflows/README.md) | Builder, registry V1–V4, execution index |
| [docs/security/README.md](../security/README.md) | Security hub linking existing guides |
| [docs/reference/repository-cleanup-report.md](repository-cleanup-report.md) | This report |

## Documentation archived

No acceptance reports were moved. [docs/archive/README.md](../archive/README.md) was refreshed so “current replacement” cells point at capability guides. Historical bodies of `phase8` through `phase12` and `integration-foundation.md` were not rewritten.

## Diagrams updated

| Diagram | Change |
| --- | --- |
| system-context | Added private Go AI and OpenAI/Gemini |
| component-architecture | NodeRegistry V1–V4; Go sidecar; AI jobs |
| deployment-topology | Native Go on 8020; Compose private Go |
| workflow-execution | Advisory AI queue branch returning to Laravel |
| database-erd | Advisory AI subsystem ERD |
| release-candidate-operations | Renamed from phase12-operations; same content retitled |
| diagrams/README | Capability link text; cleanup report pointer |

Rendered 25 Mermaid diagrams via `node scripts/render-diagrams.mjs` after installing the pinned Puppeteer chrome-headless-shell for the docs toolchain.

## Tests executed

| Check | Result |
| --- | --- |
| Frontend Vitest (`npm test -- --maxWorkers=2`) | Passed — 29 files, 126 tests |
| Frontend TypeScript (`npm run typecheck`) | Passed |
| Frontend ESLint (`npm run lint`) | Passed |
| Frontend production build (`npm run build`) | Passed |
| Laravel Pint (`vendor/bin/pint --dirty`) | Passed |
| Laravel suite (`php artisan test --compact`) | Passed — 251 passed, 14 skipped, 3213 assertions |
| Guarded PostgreSQL (`phpunit.pgsql.xml`) | Passed — 14 tests, 125 assertions |
| Go `gofmt -l .` | Clean (no files listed) |
| Go `go vet ./...` | Passed (via `go test` toolchain) |
| Go `go test ./...` | Passed — all packages |
| Go `go test -race ./...` | Not run — Windows requires CGO; Linux QA image remains the documented race path |
| Docs links (`node scripts/check-docs.mjs`) | Re-run after this report; expected 0 missing |
| Mermaid render | Passed — 25 diagrams |

## Showcase verification

Executed against already-running native (`http://localhost:3010`) and Docker (`http://localhost:3011`) installations. No reseed, reset or volume delete.

| Check | Result |
| --- | --- |
| Native Admin login | Passed; fictional demo banner visible |
| Navigation (capability labels) | Passed — Overview, Tasks, Requests, Workflows, Connections, Analytics, Team, Settings |
| Workflows list and builder | Passed — studio controls Save/Check/Publish/Tidy/Undo; AI composed workflow opened |
| Requests list | Passed |
| Connections | Passed; simulated provenance labels intact |
| Analytics and audit history | Passed |
| Manager role switch (logout/login) | Passed |
| Manager task Approve/Reject controls | Visible on an awaiting-approval task; decision not submitted (preserve fixtures) |
| Docker Admin login | Passed; banner visible; no phase labels on dashboard |
| New request submission end-to-end | Not re-run in this pass (would add guided requests); existing request inventory and task decision UI verified |
| Live / paid AI | Not exercised |

## Remaining technical debt

- Live-provider verification for Telegram, email, Slack, SMS, OpenAI and Gemini.
- Production PKI, DB TLS, secret custody, ClamAV/PDF sandbox, retention, alerts, PITR and deployment approval ([outstanding work](outstanding-work.md)).
- Screen-reader, 200% zoom, reduced-motion and broader accessibility certification.
- Residual phase-named labels inside preserved demo DB rows and historical workflow titles.
- Windows native Go race tests require CGO toolchain; use Linux QA image.
- Optional: rename historical QA env filenames only with a full reference update (not done).

## Items deliberately preserved

- All historical acceptance reports at original paths
- Published workflow versions, snapshots, audit events and permissions
- Demo passwords and seeded data (no reseed)
- Phase-named QA env profiles, DB names and Compose project names
- Phase-named PHPUnit classes and concurrency PostgreSQL suite target
- NodeRegistry V1/V2/V3/V4 contracts
- Prior housekeeping allowlist and ignore rules
- Security controls (auth, CSRF, RBAC, vault, simulation guards)
- Staging smoke fixture name `Phase 12 Isolated Staging`

## Unverified areas

- Full end-to-end new request create → approve mutation in this pass (controls verified; fixture mutation avoided)
- Docker deep walkthrough beyond Admin login (native covered more surfaces)
- Go race detector on this Windows host
- Live providers and paid inference
- Production deployment

## Readiness summary

| Use | Ready? |
| --- | --- |
| Developer onboarding | Yes — capability docs index and README map |
| Local showcase | Yes — verified in this pass |
| Docker showcase | Yes — login and banner verified; deeper paths rely on prior acceptance plus this login check |
| Portfolio handoff | Yes for source, docs and diagrams; historical reports remain evidence |
| Production deployment | No — NOT CLEARED; see [outstanding work](outstanding-work.md) and [production checklist](../operations/production-readiness-checklist.md) |
