# Phase 11 AI and Go acceptance

Status on 2026-11-05: **implementation and simulated acceptance complete**. Native and Docker demonstration checks passed. This is not a production or live-provider approval. Phase 10 and earlier reports retain their historical evidence; SHA-256 checks matched the initial inventory for the six existing Phase 8 through Phase 10 reports.

## Preserved data and architecture

The initial working tree inventory is retained privately under `backend/storage/app/phase11-audit`. No commits, pushes, hosted resets, production credentials or real provider messages were used. The owned native and container PostgreSQL databases and vault/application keys were preserved. Native baseline verification retained 24 users, eight original published workflows and 100 historical requests, plus two prior rehearsal requests. Container baseline verification retained its original 100 requests. The AI extension adds separate workflows, requests, scoped grants and a scenario ledger; it does not rewrite the original manifest or published snapshots.

Laravel retains Sanctum/CSRF, organization RBAC, vault custody, immutable publication, workflow state, human decisions and snapshots. Go owns new advisory AI processing with bounded authenticated REST endpoints. Existing extraction remains PHP after a measured rejection of migration. See [architecture](architecture/go-service.md), [AI setup and permissions](integrations/ai.md), and [migration measurements](performance/go-migration-results.md).

## Executed checks

| Check | Actual result |
| --- | --- |
| Native Go unit/contract tests and vet | Passed; fixed-host provider clients tested with fake responses, no live calls |
| Linux Go race/vet/gofmt QA image | Passed with Go 1.27.3 and CGO in QA; static unprivileged runtime |
| Full Laravel suite | 260 cases: 246 passed, 14 PostgreSQL skips, 3,200 assertions; 193.387 seconds |
| Guarded PostgreSQL suite | All 14 passed, 125 assertions; 52.898 seconds. Includes two-process AI dedup/reservation and worker single-claim tests |
| Final affected Laravel rerun | All 30 passed, 337 assertions after composed rehearsal addition; transport/security/workflow/email cases |
| Full Vitest | 80 passed across 23 files with bounded two-worker concurrency; 66.47 seconds |
| TypeScript and full ESLint | Passed; checked again after final frontend type edits |
| Pint | Applied; final changed-PHP formatting check passed |
| Native forward migrations | Two additive AI migrations applied after local ownership verification |
| Native AI extension | Ten scenarios and composed rehearsal verified through signed local Go calls and simulated providers |
| Docker Compose production build/start | Passed with final behavior; subsequent edits were formatting/tooling/docs only. Private Go healthy, no exposed Go/PostgreSQL port, volumes retained |
| Container AI extension | Ten scenarios and composed rehearsal independently verified; original baseline preserved |
| Native production build/launcher | Passed; production frontend, API, worker, scheduler and loopback Go running |
| Browser acceptance | Three authenticated roles, connection lifecycle, V4 builder, advice, mobile/tablet and both themes; details below |
| Documentation | 117 local file targets checked, no missing targets; remote URLs and anchors not automatically validated |
| Mermaid | 21 diagrams rendered to SVG and PNG; four new AI diagrams visually inspected |
| Synthetic extraction comparison | 80 matching samples, zero throttles; PHP retained; see measurements |

The native/container extension verifies email summary, real DOCX extraction with simulated scanner, missing fields, an unsent correction draft, authenticated revision and revalidation, linked Telegram Manager approval with one decision snapshot, verified Slack activation, classification with constrained advisory routing options, uncertain advice and provider timeout/budget fallback. Scenario labels may share a request: these are ten checks, not a claim of ten independent new requests. The classification fixture does not authorize routing or activate another workflow.

## Corrections found during acceptance

PostgreSQL JSONB reorders published object keys. Strict configuration array equality incorrectly denied queued AI work; the fix uses the existing canonical definition hasher and has a representation-order regression. The native scenario suite caught this before acceptance. Docker also caught two edited files with invalid UTF-8; those files were repaired before the successful production build. A missing eligible Manager in an early test fixture was corrected without weakening runtime eligibility. Browser metadata testing caught an organization route-parameter binding error; resource actions now explicitly bind the organization before the connection/run/request parameter, covered by an HTTP regression. An early unrestricted Vitest rerun overlapped an unfinished PHP subprocess and hit five existing five-second timeouts; the subsequent isolated two-worker full run passed without relaxing assertions or timeouts. The final lint run initially scanned the new generated native build directory; it was stopped, the Phase 11 directory was added to the existing generated-output exclusions, and full lint then passed. Final whitespace checks passed; line-ending-only cleanup did not change application behavior.

## Composed rehearsal and preserved records

The separate `mindco-ai-leave-v1` rehearsal passed on both installations. One request proceeds through verified synthetic email, actual DOCX extraction, AI missing-field advice, a fixed correction email, authenticated Employee revision, AI field revalidation and summary, existing linked Telegram Manager approval, and fixed employee/HR outcome emails. Each has three completed AI runs, three simulated accepted email actions, one initial snapshot, one revision snapshot and one decision snapshot. Repeated `--rehearse --verify` succeeded without creating another request. The commands and presentation instructions are in [demo setup](demo/demo-setup.md) and the [MINDCo script](demo/demo-script.md).

Native evidence request: `16225522-98a4-4116-ba81-1fab9c4e70e5`; Docker evidence request: `6ef3abf7-c896-41da-8d59-5463969bb13f`. Final baseline verification retained 24 original users, eight original workflows and all 100 original historical requests on each installation. Workspace totals are 110 native and 108 Docker: native includes two pre-existing rehearsals; each AI extension adds seven requests plus the composed rehearsal. The extra browser lifecycle connection is synthetic, revoked and separate from seeded connections.

## Browser acceptance

- Admin: ordinary login, AI policy and approved connection display; synthetic Gemini metadata test succeeded; disable and revoke completed, with stored-key removal and no remaining lifecycle actions. Original seeded credentials remained unchanged.
- Manager: ordinary logout/login, read-only policy/connection presentation without admin lifecycle controls; reviewed authorized AI advice beside original evidence and the completed linked Telegram decision.
- Employee: ordinary logout/login, no authoring/shared administration controls; personal creation unavailable under the seeded organization policy. Revalidated advice is available on the employee's own request; superseded advice is unavailable after the source changes.
- Native V4 builder: all seven AI node renderers/palette entries, four outcomes, approved connection/grant selection and typed source/schema controls verified. Tidy then Undo restored `Draft saved` and disabled Save draft; no draft or publication was changed. Validation correctly refuses republishing an unchanged version. At 390 pixels the builder presents read-only guidance.
- Layouts: AI connections at 390 x 844 in dark and light themes, Manager advice at 820 x 1180 in light theme, native builder/request at 1280 x 900 in dark theme. No clipped connection controls observed. Session expiration returned to login; ordinary login restored access. This is a focused matrix, not every page at every viewport/theme/role combination.
- Native composed request visibly shows the Employee revision, three AI evaluations, linked Manager approval, completion and three email acceptances. The fictional-demo notice and advice provenance identify simulation. Provider acceptance is not confirmed delivery.

Cross-tenant isolation, stale membership/grants, personal privacy, step-up/CSRF/session enforcement, credential revocation after a response, rotation, retention, malformed results, pure no-side-effect preview and unchanged older registry execution are covered by automated tests. PostgreSQL profiles were preserved before the guarded run and restored with matching SHA-256 hashes afterward. No hosted or existing QA database was reset.

## Migration report and verdicts

Only two forward migrations were added for this phase: `2026_11_04_112527_add_ai_processing_foundation.php` (five AI tables, two provider registrations and awaiting-AI workflow state) and `2026_11_04_121833_create_ai_demo_scenarios_table.php` (owned demo scenario ledger). Both ran on native and Docker demo targets. No historical migration, publication snapshot or registry V1/V2/V3 contract was rewritten. V4 is used only for the new AI nodes. Runtime-permission SQL is an operator-reviewed proposal, not evidence of production grants applied.

Implementation, simulated demonstration, documentation and rendered-diagram acceptance pass within this scope. Go owns the new advisory AI boundary; the measured old extraction migration was rejected. Native and Docker remain usable with preserved data. Full screen-reader, keyboard/focus, zoom and reduced-motion coverage for the new screens was not independently completed; no blanket accessibility compliance is asserted. Phase 12 deployment has not begun.

## Live and production gates

OpenAI, Gemini and all external message delivery remain **independently unverified**. No live credentials or paid inference were used. Follow the website setup steps in the [AI connection guide](integrations/ai.md) on a separately configured non-demo installation. Demo profiles deliberately reject real credentials and transports. Provider model availability, account access, content quality and actual billing must be verified with authorized test accounts before claiming live support.

Production TLS/secret distribution, least-privilege runtime grants, scanner/PDF isolation, provider data-processing/retention approval, backups/restoration, queue supervision, monitoring and deployment remain separate gates. No production deployment or blanket accessibility compliance is claimed.
