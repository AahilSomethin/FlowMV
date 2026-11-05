# Phase 10 — Product and showcase acceptance

Final assessment: **accepted for the controlled local showcase, with the limits below**. Updated 2026-11-04 (Asia/Tashkent); implementation and verification ran across November 2–4. This does not clear real providers, accessibility certification or production deployment.

| Verdict | Assessment |
| --- | --- |
| Demo readiness | Native and Docker setup, guarded datasets and production-browser walkthroughs passed. Four MINDCo scenarios rehearsed with ordinary role changes. |
| Documentation | Current onboarding, architecture, API, security, connector, operations, testing and presentation guides delivered; historical evidence preserved. Proposed production procedures are labeled. |
| Diagrams | 17 Mermaid diagrams parsed/rendered to SVG and PNG; contact sheet and representative full-size renders inspected. |
| Technical acceptance | Laravel, guarded PostgreSQL, Vitest, TypeScript, ESLint, Pint and native/container production builds passed. Specific skips and reruns appear below. |
| Phase 11 readiness | Ready for requirements/planning against the documented boundaries. AI/document intelligence was not implemented or approved by this phase. |
| Production readiness | Blocked on independent providers, runtime least privilege, scanner/PDF isolation, retention, operations and deployment gates. |

## Preservation and audit

The initial inventory covered 549 project-owned files and 165 preexisting changed/untracked entries. Initial status/source hashes, routes and performance evidence remain in ignored `backend/storage/app/phase10-audit`. Existing uncommitted implementation, QA accounts/databases, private evidence, authentication, RBAC, V1/V2/V3 contracts and snapshots were retained. No commit, push, hosted reset, production credential change or real demo message occurred.

Nine existing reports/references were checked against the initial SHA-256 inventory and remain unchanged: Phase 8, 8.5, 9B, 9C, 9D, integration foundation, database access, cache/rate limits and snapshot retention. Historical reports retain their paths. No standalone Phase 1–7 acceptance reports were found; their results were not reconstructed. See [repository and Markdown authority inventory](architecture/repository-inventory.md) and [archive index](archive/README.md).

| Milestone | Delivered evidence |
| --- | --- |
| 10.1 Audit/baseline | Source/route/Markdown inventory, page findings register, pre-optimization 10,000-sample deterministic baseline, measured host/workload limits |
| 10.2 Product UX | Shared provider cards/provenance, safe version comparison, provider node presentation, mobile title/read-only guidance; existing search/tidy/undo/redo/unsaved protection retained |
| 10.3 Templates/preview | Ten versioned recipes, binding wizard and draft-only domain creation; pure bounded preview with safe findings, fingerprint and stale-result invalidation |
| 10.4 Reproducible demo | Independent native and Docker secrets/targets; guarded setup, chronological domain seeding and repeat verification; 24 users, five department metadata labels, eight published workflows, 100 baseline requests |
| 10.5–10.6 Documentation | Root README, central index, consolidated substantive guides, 17 diagrams, pinned development-only validation tooling and 12–15 minute script |
| 10.7 Acceptance | Sequential automated checks, seed integrity, production-browser role/theme/responsive rehearsal and explicit accessibility limits |
| 10.8 Performance/Go | API, SQL, ingestion, parsing and durable-worker measurements; no qualifying CPU workload, so Go omitted |

## Security and compatibility

Template catalog APIs are organization scoped. Instantiation requires workflow-create permission and calls existing creation/draft services; it never publishes, creates subscriptions or grants credentials. Provider recipes require real approved references before a valid draft can be created. Missing bindings focus the required selector and link to setup.

Preview requires workflow-authoring permission and returns a definition fingerprint, executed/skipped/blocked trace, chosen branches and safe findings. It evaluates unsaved editor definitions without calling production execution handlers. It cannot decrypt credentials, invoke transports, enqueue work or write requests, workflows, decisions or audit records. Pure conditions/message rules are shared with execution; unsupported behavior is unavailable. Upload-dependent forms are unavailable and invalid synthetic form fields produce safe field findings. The existing graph limits remain, with 320 transitions and a five-second evaluation deadline; reaching a bound reports incomplete evaluation. Frontend edits and late responses cannot retain a stale trace.

Tests cover tenant/role denial, stale grants, personal credential privacy, revoked connections, webhook forgery/replay, concurrent decisions and V1/V2/V3 published behavior. Comparison returns only structural changes, excluding message/configuration values, submissions and credential references. Historical null provenance remains unknown. Successful provider response provenance is distinct from confirmed delivery; simulated operations remain simulated. No authorization cache across requests/jobs, unrestricted HTTP node or arbitrary code execution was introduced.

The separate demo guard checks environment, exact target/role/owner, actual database identity/address, installation marker and all simulated transports. Hosted/wrong-target refusal occurs before synthetic mutation. Existing phase-specific QA guards remain intact. The fictional-workspace banner derives from server-owned state and cannot be enabled by a client query flag.

## Sequential verification ledger

These are actual Phase 10 results, not inherited Phase 9 claims. Checks ran one at a time; focused fixes were verified before the final suite results. No application code changed after those passing suites. The subsequent Windows launcher fix was separately syntax-checked and exercised through a clean restart.

| Check / target | Final actual result |
| --- | --- |
| `php artisan test --compact` | 239 cases: 226 passed, 13 PostgreSQL cases skipped in SQLite; 2,999 assertions; exit 0 |
| `php vendor/bin/phpunit --configuration=phpunit.pgsql.xml` | All 13 PostgreSQL cases passed, 120 assertions; exit 0; actual locks/concurrency/constraints |
| Focused simulation/security run | 107 cases passed, 1,529 assertions before the last preview regression; the final full suite includes that additional coverage |
| Final affected preview file | Six cases passed, 91 assertions, including safe invalid inputs and unavailable uploads |
| `npm test -- --maxWorkers=2` | 74 tests in 22 files passed; exit 0 |
| `npm run typecheck` | Passed |
| `npm run lint` | Passed |
| `php vendor/bin/pint --dirty --format agent` | Passed after formatting affected PHP; full Laravel suite passed afterward |
| Native production build | Passed; 31 pages, separate `.next-phase10-native-production` output; production app exercised on 3010/8010 |
| Docker production build | Passed from pinned images; production app independently exercised on 3011/8011 |
| Native forward migrations / seed / repeat seed | Passed against new owned cluster; repeats verify without overwrite |
| Docker forward migrations / seed / repeat seed | Passed against separate owned volumes and generated secrets |
| Native final integrity | Baseline 24 users / eight workflows / 100 requests verified; workspace has 102 after two guided cases |
| Docker final integrity | Baseline and workspace remain 24 users / eight workflows / 100 requests |
| Native restart regression | Clean stop/start of only the owned demo cluster passed after pg_ctl output-handle fix; nothing to migrate, 102 requests preserved |
| Authenticated HTTP workload | Native and Docker each returned 20/20 request-list and 20/20 analytics successes, no 429s |
| Durable document queue | 40/40 real database jobs completed with simulated scanning and real bounded DOCX extraction |
| Script syntax / whitespace | All project `.mjs` launch/validation scripts passed `node --check`; `git diff --check` passed with existing line-ending notices |
| Local Markdown file links | 83 local file links checked, zero missing targets; anchors and remote URLs are outside this check |
| Mermaid | All 17 finalized diagrams parsed and rendered to SVG/PNG; no runtime dependency added |

PostgreSQL tests target only the guarded loopback `flowmv_phase85_test` on port 55485. The preexisting test profile was backed up before rerunning and restored afterward; new measurements have separate names. The existing QA cluster was resumed, not initialized/reset. SQLite skips were covered by the separate passing PostgreSQL run.

## Failures, fixes and practical limits

Initial failures were retained as evidence and resolved:

- PostgreSQL shared database comments require `shobj_description`, not `obj_description`; ownership checks were corrected.
- The reserved synthetic email guard requires exact `@example.com` addresses; seed identities were corrected.
- Telegram HTTP request links remain forbidden outside a successfully verified owned demo; production HTTPS checks remain enforced.
- A shared frontend type/component name collision was corrected before passing builds.
- An internal-only Compose network prevented host ingress. Database/workers stay on the private network; web/API join a separate bridge and publish only IPv4/IPv6 loopback ports. Both address families returned HTTP 200.
- Initial Vitest failures involved API fixture shape, an accidental cleanup return and shared-host worker oversubscription. Route-aware mocks and two workers resolved them without skipped tests or weakened assertions.
- ESLint initially traversed generated Phase 10 build output; the generated directory is ignored and the final lint passed. Final preservation review restored the original root dependency/environment/storage ignore rules and added only the generated diagram exclusion.
- One Windows ZIP temporary rename failed once; its unchanged case and later full suites passed. A different ciphertext test incorrectly searched for `PK` anywhere in random ciphertext; it now checks the ZIP header, absence of full encoded plaintext, authenticated decryption and plaintext hash.
- Stale preview results after changed inputs/definitions or late responses were fixed and covered by two UI regressions. Invalid form answers now return field findings instead of a generic sensitive-operation error.
- The initial PostgreSQL run failed while the local server was stopped. A later Artisan wrapper returned nonzero despite a passing summary; the direct configured PHPUnit run passed with exit 0.
- After the computer session interruption, services were stopped. Native PostgreSQL recovered its existing WAL/data. Windows inherited capture handles caused a startup timeout after pg_ctl had successfully started the server. pg_ctl now uses ignored output handles; a clean stop/start and guarded integrity verification passed. Docker resumed using the same owned volumes/secrets.

The native PHP development API server serializes work and had variable boot/response delays under shared Windows load. Browser automation sometimes timed out before a completed response; final states were inspected before claims. Container latency was lower in the measured local workload, but this is not a code speedup or production SLA. Production load testing remains separate.

## Dataset, migrations and ownership

Both fresh installations created one fictional MINDCo organization, two Admins, six Managers and sixteen Employees. Five department labels are demo metadata only. The fixture clock produced chronological requests/tasks/events/decisions through domain services. No decision was backfilled and no immutable timestamp rewritten. Baseline outcomes remain 73 completed, 10 rejected, nine awaiting approval, six awaiting assignment and two awaiting revision.

Native uses the owned loopback PostgreSQL cluster on port 55490 and `flowmv_phase10_demo`. Docker uses its independent private postgres service, owned named volumes and installation marker. The database is not published. Docker Linux engine 29.5.3 was verified. Application ports are loopback only. Generated keys, vault keys, installation tokens, DB passwords and account passwords remain in ignored private files/volumes. Setup does not reuse QA or hosted credentials and provides no destructive reset.

| Phase 10 forward migration | Result / historical treatment |
| --- | --- |
| `2026_11_02_122919_add_operation_evidence_to_integration_audit` | Applied in both fresh targets; nullable provenance, no historical backfill or fabricated live label |
| `2026_11_02_130050_create_demo_datasets_table` | Applied in both fresh targets; ownership/recipe manifest with no passwords |

All earlier schema migrations also ran in each fresh installation. Existing installations need forward migration before using the evidence column. Down methods intentionally refuse destructive rollback. No new registry version was introduced.

## Browser acceptance and four-scenario rehearsal

The native production bundle used ordinary Admin → Employee → Manager → Admin logouts/logins. Passwords were read privately from generated local manifests and never printed. An expired session redirected to login after services recovered; server unavailability showed a retryable account error without pretending the user had been logged out.

| Scenario | Actual result |
| --- | --- |
| A — configure/publish | Existing extra rehearsal draft reused. Template search, hypothetical approval, check workflow, tidy/undo and publication passed. A layout-only second version was published on this extra workflow; comparison showed moved steps with routing/form unchanged and excluded private values. |
| B — email/document correction | New synthetic missing-ID email passed verified ingress and real DOCX extraction. Employee 1 confirmed its own intake, resubmitted `DEMO-901` through Revision, then Manager 1 approved. Request `d4511714-1036-4336-bca6-c954105e93ba` is completed with decision evidence and simulated correction/outcome acceptance. |
| C — Slack → Telegram → outcomes | Linked synthetic Slack sender submitted a new event and the identical key was submitted again. Only one new Slack request resulted. Manager 1 used the unexpired simulated Telegram Approve callback with step-up. Request `fb3edcc5-aae7-4145-b236-62bc6821028b` completed; timeline records linked-identity decision, scoped Slack acceptance and SMS acceptance, later simulated delivered status. |
| D — evidence/roles | Admin 90-day UTC analytics shows 102 requests, 75 completed and 10 rejected; baseline integrity independently verifies the original 100. Manager/Employee analytics explicitly limits scope. Employee authoring is read-only, shared administration controls are absent and personal creation is disabled by policy. Team explains Admin-only membership controls. |

The extra native workflow is `a17a381c-2de7-456c-b913-a73663ad4c4a`, versions 1 and 2. The eight original published workflows/snapshots and 100 baseline records remain unchanged. A separate synthetic Telegram connection, “Phase 10 lifecycle rehearsal,” was created, tested, disabled and revoked through the website with step-up. Disabled test/use controls and then revoked status were verified. It had no workflow grants; the seeded bot stayed enabled. No real bot token was used.

Both final native and Docker editor previews showed three executed steps, the chosen approved branch, one skipped step and a fingerprint. Native invalid inputs produced three field alerts; changing sample input removed the old trace. Docker login, seeded published workflow, independent preview and simulated connection summaries passed. Docker remained at 100 requests after preview.

### Responsive and accessibility evidence

Viewport cases used desktop 1280×900, tablet 768×1024 and mobile 390×844. Scrollbars reduce content width by 15 pixels where present. Loaded sampled views had document scroll width equal to client width. This is representative page coverage, not an exhaustive Cartesian test of every page at every setting.

| Role | Desktop light / dark | Tablet light / dark | Mobile light / dark |
| --- | --- | --- | --- |
| Admin | Publishing/comparison/analytics / builder and preview | Email setup / template catalog | SMS/provider cards / Telegram lifecycle |
| Manager | Tasks and completed requests / team and settings | Provider cards / scoped analytics | Task inbox / completed request timeline |
| Employee | Telegram administration denial / overview | Team view-only / scoped analytics | Workflow read-only title/layout / task links |

Authentication, overview, tasks, request timeline, workflows/builder/templates, provider setup, analytics, team and settings were exercised across these cases. Mobile authoring is explicitly read-only. The long builder title and footer remain readable. Original System theme preference and temporary viewport override were restored.

Wizard initial focus, Escape dismissal and focus restoration to “Use template” were verified. Missing provider selection triggered native validation and focused the required connection field. Preview field errors have alert semantics and the result has status semantics. Form labels, skip link and role explanations were inspected. This does not prove a screen-reader experience.

**Unverified accessibility requirements:** screen-reader session, measured contrast, effective 200% browser zoom and reduced-motion emulation. The in-app browser ignored zoom shortcuts (pixel ratio/viewport unchanged) and exposes no motion-emulation capability. No WCAG certification, comprehensive keyboard audit or cross-browser/device certification is claimed.

Safe screenshots are retained privately under `backend/storage/app/phase10-audit/screenshots`, including production preview, completed correction, linked Telegram outcome, mobile Employee read-only/tasks, tablet provider cards and Docker production summaries. The [12–15 minute presentation script](demo/demo-script.md) was exercised through its four scenarios; repeat runs must use fresh event keys and retain new records.

## Documentation, diagrams and performance

The [central index](README.md) links substantive guides for setup, architecture, security, APIs/routes, connectors, workers/recovery, runtime permissions, retention, backup/incident procedures and maintenance. Production procedures are proposals unless independently verified. Licensing remains an owner decision; no application license was invented. Diagram tooling is development-only, pinned in its own lockfile. ERDs are split into organization/workflow, credentials/Telegram, email/documents, Slack and SMS. Implemented demo topology is separate from proposed production deployment.

All 17 diagrams rendered successfully after the final layout edits. Contact-sheet review covered the set; full-size email ERD, deployment topology, Slack/SMS flow and representative component/sequence figures were inspected. Generated SVG/PNG outputs are ignored. Local-link validation checks file existence only, not anchors, remote sites or a hosted documentation renderer.

See [performance and Go evaluation](performance/go-evaluation.md) for workloads, hardware, SQL/CPU/resource scope and raw evidence locations. Component profiles use 100 samples, HTTP uses 20 successes per endpoint at concurrency two, and the dedicated queue uses 40 jobs at concurrency one. p99 appears only for the 10,000-sample deterministic baseline. No transport delay was injected. DOCX normalization/extraction did not meet the 25% CPU eligibility gate; no Go prototype or service was added and no optimization speedup was invented.

## Remaining release gates

Real Telegram, Gmail, Outlook, Slack and Twilio verification remains pending with dedicated test accounts and independently observed provider/recipient evidence. Enter real credentials only in an appropriately configured non-demo FlowMV environment, never in this guarded showcase. The [integration guide](integrations/README.md) gives website setup steps, required HTTPS/OAuth/webhooks, approvals, destinations, identity linking, workflow grants and independent verification. No exactly-once Telegram/SMS delivery guarantee is made.

Production scanner and isolated PDF runtime, least-privilege runtime roles, protected secret custody, approved retention, supervised workers/monitoring, restore rehearsal, operational ownership and explicit deployment approval remain required. Phase 11 AI and Phase 12 deployment are separate work. See [outstanding work](reference/outstanding-work.md).
