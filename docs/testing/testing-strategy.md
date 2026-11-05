# Testing and acceptance

Use focused tests while implementing, then run final checks **sequentially**, recording failures and reruns. The [Phase 10 ledger](../phase10-acceptance.md) is the result source; commands listed here are instructions, not an assertion that they passed.

## Backend and PostgreSQL

From backend:

```powershell
php artisan test --compact
php vendor/bin/phpunit --configuration=phpunit.pgsql.xml
php vendor/bin/pint --dirty --format agent
```

The ordinary suite uses isolated SQLite with production transports disabled. The PostgreSQL suite is guarded to loopback `flowmv_phase85_test` on port 55485 and refuses wrong/hosted targets before migration. It tests actual locking/concurrency and PostgreSQL constraints. Preserve its existing private profile evidence before rerunning; never reset a QA or hosted database to satisfy a test. Existing QA datasets are not PHPUnit's database. If the guarded PostgreSQL server is unavailable, report a skip/blocker rather than treating SQLite as equivalent.

Focused Phase 10 files cover template authorization/valid definitions, draft-only instantiation, safe structural comparisons, provenance/privacy and preview. Preview tests forbid vault decryption, transport invocation, jobs, mail and domain/audit records even with configured synthetic ciphertext and live-mode flags. They cover stale grants, tenant/role denial and bounded revision traces. Regression coverage of published V1/V2/V3, webhook forgery/replay, concurrent decisions and revocation remains mandatory.

## Frontend and production build

From frontend, run these one at a time:

```powershell
npm test -- --maxWorkers=2
npm run typecheck
npm run lint
npm run build
```

The bounded Vitest worker count avoids oversubscribing the shared 16 GiB host; it does not skip tests or relax assertions. Vitest configuration now uses an explicit ESM extension and import.meta.dirname, removing the previous future-loader warning.

Production build uses the documented public API URL and cookie/dist configuration for the target. A dev-server walkthrough does not prove a production bundle or fresh install. JSX/TypeScript parsing, lint and UI behavior are distinct checks. Keep unrelated preexisting work intact when fixing failures.

## Simulation and seed integrity

`php artisan demo:verify --env=demo` verifies the owned showcase. `demo:seed` repeats verification without overwriting an existing ledger. A strict pristine dataset has 24 users, eight published versions and 100 baseline requests, including real decisions, corrections, encrypted DOCX evidence and outboxes. Integrity verifies the original manifest IDs and also checks analytics against current workspace totals. Additional guided requests and separately created workflows are retained; mutations of original fixture records can legitimately report baseline drift. Never reset or force records back to expected values.

Editor preview is nonpersistent and hypothetical. Guided showcase execution uses actual Laravel requests/tasks/decisions/outboxes under the server-owned demo guard; all provider responses and scanning are simulated. Test environment/actual identity/owner/installation marker and transport flags. Wrong-host refusal must occur before mutation. No production provider tokens belong in fixtures.

## Browser acceptance

Use normal login/logout for Admin, Manager and Employee. Review authentication, workspaces, tasks, requests, workflows/builder, templates, Connections/providers, analytics, team and settings at desktop, tablet and 390px mobile in both themes. Preserve existing datasets and use synthetic accounts only. Test template binding and missing-setup guidance, hypothetical decisions, blocked grants, connection disable/revoke, stale sessions and authorization messages. Re-enable only credentials whose safe policy permits it; do not restore revoked tokens by database editing.

Check visible labels, keyboard tab/escape behavior, focus restoration after dialogs, error/status announcements, skip link, 200% zoom, reduced motion and both themes. Record exactly what was checked. Screen-reader experience, contrast certification and broader device support must remain unverified unless actually tested. Screenshot evidence should show fictional content and never passwords, tokens or private real messages.

## Documentation

Run local links and Mermaid renders through the development-only tooling described in the [documentation index](../README.md). Inspect rendered SVGs. Archive reports remain at their original paths. Missing historical evidence stays missing; never reconstruct pass counts from memory.

## Phase 11

Run `go test ./...` and `go vet ./...` in `services/ai-go`. Run the digest-pinned Linux QA image target for CGO race checks: `docker build --target qa -f docker/demo/ai-go.Dockerfile -t flowmv-phase11-go-qa .`. Native Windows race checking requires a C compiler; Linux QA is the verified alternative. Focused Laravel AI tests cover transport signing, personal privacy, encrypted results, revocation, retention, canonical JSON and pinned execution. The guarded PostgreSQL suite adds two-process AI reservation and worker claiming. Frontend AI tests cover disclosure, privacy and focus. Final results belong in the [Phase 11 ledger](../phase11-ai-go-acceptance.md), not historical reports.

## Phase 12 checks

See the [current acceptance ledger](../phase12-final-acceptance.md). Run `node --test scripts/staging-guards.test.mjs` from root and follow the isolated staging commands in the [production checklist](../operations/production-readiness-checklist.md). Axe 4.14.0 checks rendered login/error/dialog semantics; keyboard tests check focus and dismissal. Opaque theme-token contrast tests cover specified text and control pairs, not every rendered/composited state. Browser screen-reader, zoom/motion and device limitations must remain explicit. Go govulncheck uses pinned development tool v1.9.0; formatter runs used Prettier 3.9.10 without adding a runtime dependency.
