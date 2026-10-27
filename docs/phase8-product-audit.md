# FlowMV product audit and UX transformation

Audit date: 27 October 2026. Scope: the existing Next.js/Laravel application, through Phase 8 only.

## Outcome and verification limits

The application now has a coherent light/dark identity, a workflow studio with shared node presentation metadata, accessible connection selectors, a searchable service catalog, request review before submission, clearer tracking, and a more usable task inbox. The execution engine, authorization policies, migrations, published-version contracts, idempotency implementation, and database schema were not changed.

The only application API extension is an optional task `instructions` display field, read from the instance's pinned workflow version. A regression test proves that editing the newer draft does not change an existing task's instructions.

This report distinguishes source-confirmed defects, browser observations, automated coverage, and recommendations. Passing the default automated suite does not establish PostgreSQL concurrency safety or complete live coverage of every role and theme. Remaining release checks appear below.

## Repository and journey coverage

| Area | Source inspected and behavior considered |
| --- | --- |
| Entry and identity | Root redirect, login, registration, onboarding, verification links, invitation acceptance; AuthProvider, API/CSRF handling, proxy, auth controllers and tests |
| Workspace shell | Overview, navigation, breadcrumbs, organization switcher, account menu, keyed organization transitions, sidebar, settings and theme initialization |
| Workflow management | List, creation dialog, metadata/detail screen, archive/publication dialogs, version history, visible-graph selection |
| Studio | Canvas, node palette, node rendering, inspector, form builder, draft loading/saving, validation, history, keyboard shortcuts, unsaved-change guard |
| Contracts | Frontend types/validation/editor state and Laravel NodeRegistry V1/V2, normalizer, validator, publication action, version-pinned engine |
| Requests | List/search/status/pagination, service discovery, dynamic fields, validation/review, idempotent submission, detail summary, activity and decision snapshots |
| Tasks | Open/completed inbox, search/pagination, task preview/detail, approvals, assignment completion, revisions, stale decisions and idempotency keys |
| Analytics and audit | Date/status/workflow/actor filters, charts, scope banners, exports, snapshots, event labels and SQL aggregate implementation |
| Team | Membership listing, role changes, invitation flow, last-admin protection, organization policies and membership locators |
| Reliability/security | ActiveMembershipLocator, SyncCurrentOrganization, WorkflowPolicy, instance visibility, idempotent transactions, pinned execution, notification/delay/assignment services and regression suites |

Route families covered include `/`, `/login`, `/register`, `/onboarding`, `/verify-email/[id]/[hash]`, `/invitations/accept`, `/dashboard`, `/workflows`, `/workflows/[id]`, `/workflows/[id]/edit`, `/requests`, `/requests/new`, `/requests/[id]`, `/tasks`, `/tasks/[id]`, `/analytics`, `/analytics/audit`, `/team`, and `/settings`. Laravel remains the authority for access to every underlying resource; visible frontend actions are not authorization enforcement.

## Findings by severity

No new Critical security defect was proven by this audit. This is not a penetration-test certification.

### High

| Finding | Evidence | Result |
| --- | --- | --- |
| A save response could discard edits made while saving | `markSaved` replaced current editor content with the response graph; focused regression reproduces the race | Fixed: retain newer edits, update the saved baseline/revision, keep the draft dirty |
| Workspace switching and logout could discard an unsaved draft | Original guard covered anchors/unload, while these actions used callbacks | Fixed: shared cancelable navigation event checked before those actions; cancellation/approval tested |
| Available services after page one could not be selected | Request creation called `listPublishedWorkflows(..., 1)` once and exposed only its results | Fixed: server-backed search, service cards and pagination; page-two and failure recovery tested |
| Assignment instructions were not delivered to task users | Published assignment config contained instructions, but WorkflowTaskResource omitted them | Fixed: additive pinned-version field plus task/revision instruction panel; pinned-version regression passes |
| Production compilation repeatedly crashed in the CSS worker | Actual build failures pointed at the prebuilt React Flow stylesheet going through the broad Tailwind loader | Narrowed the loader to `globals.css`; subsequent production builds pass. The precise internal compiler failure mechanism is not established |
| Elevated runtime database privileges are documented | Existing `docs/database-access.md` identifies the development runtime role as broader than necessary | Existing release concern; no role, credentials or hosted database settings changed |
| PostgreSQL locking coverage is not established by SQLite | Concurrency test explicitly skips unless pointed at its disposable PostgreSQL configuration | Release verification still required; hosted databases were not used for destructive concurrency tests |

### Medium

| Finding | Evidence | Result |
| --- | --- | --- |
| Search could lose focus after each edit | Requests/tasks returned a whole-screen loader whenever the query key changed | Fixed: filters remain mounted and only the result region loads; task typing/focus regression passes |
| Analytics and audit filters similarly disappeared during refresh | Whole-screen loading guard compared full query keys | Fixed after first load: preserve filters, hide outdated result regions while fetching, disable exports while refreshing |
| Failed form loading could leave an endless spinner | Error set a banner but never marked the selected form as finished loading | Fixed: explicit form-unavailable state and working return to service selection |
| Nodes lacked a consistent visual/semantic identity | Separate copy and formatting logic, no icons, broad technical palette categories | Fixed with one presentation registry for all eight nodes, four user-facing categories, icons, descriptions and summaries |
| Outcome text and handles were separated | Side handles paired with labels at the bottom of the original card | Fixed: each outcome occupies its own footer section with a matching bottom handle; protocol identifiers are unchanged |
| The editor could consume nearly all tablet width in panels | The original three-column arrangement began at the laptop breakpoint | Fixed: compact app navigation in studio, desktop side panels at 1280px, sheets below that, mobile review mode |
| A minimap covered a lower graph step | Observed in the browser while selecting the final End node | Fixed: optional smaller overview; step selector makes every node configurable without canvas hit-target dependence |
| Repeated fit-view changed the camera as node count changed | Original effect depended on node count without a one-time guard | Fixed: initial fit only, explicit fit control, bounded zoom and optional undoable tidy layout |
| Measured node changes were discarded | Controlled canvas handled positions but ignored dimensions; old dev logs contained uninitialized-drag warnings | Fixed: retain measured dimensions. Clean-browser drag verification is listed separately from historical HMR warnings |
| Dynamic fields lacked consistent required/help/error relationships | Missing refs, required indicators and several accessible description associations | Fixed: visible required markers, control refs, associated help/errors, error summary and review dialog |
| Requests and audit filters omitted newer automation states/events | Missing assignment/delay options and event labels relative to backend enums | Fixed: options and friendly labels for assignments, notifications and waits; missing failure explanations also added |
| Several help texts described an earlier product phase | Form builder claimed submissions were not collected; inspector text described future execution | Fixed with current, plain-language instructions |
| Switching from form configuration to a selected node could leave the form tab open | Observed during browser workflow setup | Fixed: selecting a step opens its settings tab |
| API operations had no deadline and unreadable success bodies could become empty objects | Source inspection plus a failed/stalled browser API interaction | Fixed: 30-second fetch deadlines, no new automatic mutation replay, and explicit failure for unreadable successful JSON; regression tests cover session preservation and retry restrictions |

### Low / design improvements

- Replaced account-data-heavy overview with clear task-oriented entry points and an explanation of Requests, My Tasks, and Workflows.
- Unified warm off-white/light surfaces and layered slate/dark surfaces while keeping Geist, shadcn/ui, Tailwind v4 and Lucide.
- Added deeper breadcrumbs, navigation purpose hints, a labeled collapsed brand link, role explanations, task icons, clearer empty states and restrained page hierarchy.
- Added native progressive disclosure for field identifiers and friendly field type labels.
- Delay settings accept seconds/minutes/hours/days while still storing the original seconds contract.
- Chronological request activity, current-step explanations, and next-action roles use only returned data. No dates, assignees, progress percentages or SLAs were fabricated.
- Reduced-motion preferences are respected for animation and transition timing.

## Before and after

| Before | After |
| --- | --- |
| Technical node categories and text-only cards | Flow control, People & approvals, Communication, Timing; recognizable icons and summaries |
| Dragging was the main connection mechanism | Canvas handles plus labeled next-step selectors, including revision return outcomes |
| Dense panels competed with the canvas | Compact studio navigation, responsive sheets, optional overview and explicit tidy/fit actions |
| Validation messages were detached from configuration | Clickable node findings and inline settings warnings |
| A dropdown containing the first service page | Searchable, paginated service cards with descriptions and Start request actions |
| Immediate request submission | Validated answers, review dialog, edit/cancel, then explicit confirmation |
| Loading replaced search controls | Persistent filters with result-level loading |
| Assignment configuration did not reach the task view | Instructions from the pinned published version appear in task details |
| Technical/stale product copy | Task-oriented overview, role help, meaningful statuses and current publishing/form guidance |

## Theme decisions and accessibility evidence

Light background: `oklch(0.978 0.004 85)`; foreground: `oklch(0.25 0.025 260)`; white cards; restrained indigo primary. Dark background: `oklch(0.20 0.018 260)`; cards: `oklch(0.245 0.02 260)`; layered slate surfaces and a lighter indigo primary. Existing semantic status and per-node tokens remain separate from execution meaning.

Static token calculations, converting OKLCH to clipped linear sRGB and WCAG luminance:

| Pair | Light | Dark |
| --- | ---: | ---: |
| Foreground / background | 15.02:1 | 17.34:1 |
| Muted foreground / background | 6.13:1 | 6.98:1 |
| Primary foreground / primary | 7.56:1 | 7.36:1 |
| Warning text / card | 7.01:1 | 9.25:1 |
| Lowest node accent / card | 5.85:1 | 6.33:1 |

These calculations cover solid token pairs, not every composited, translucent, disabled or zoomed element. Browser screenshots checked the studio at 1280×720, 1024×768 and 390×844. Tablet configuration sheets and mobile review behavior were observed. Keyboard-oriented selectors, focus styles, associated errors and confirmation cancellation are covered by source review and focused tests; a complete screen-reader certification was not performed.

## Contracts and preservation

- Start/approval/condition/revision/end retain V1 identifiers. Notification/assignment/delay remain V2 additions.
- Stored outcomes remain `out`, `approved`, `rejected`, `true`, `false`, `return`, and `exhausted`; friendly display labels do not rewrite them.
- Presentation metadata is not serialized into persisted graph nodes. No executable node type or integration was added.
- Replacement connections are one undoable editor change. Revision target config is synchronized when selecting or disconnecting the return outcome.
- Existing publication revision checks, immutable snapshots, execution version pinning, transactions, idempotency keys and Laravel policies remain in place.
- No applied migration, hosted database reset, published-version mutation, commit, push or Phase 9 implementation occurred.
- A dedicated synthetic QA administrator and organization were created through registration/onboarding. A synthetic manager invitation was sent through the normal Team UI. Credentials are omitted from this report.

## Performance measurements

- Before the loader change, repeated production builds failed inside the CSS worker. After narrowing the Tailwind rule, a successful run reported 22.1 s compilation, 17.6 s TypeScript and 5.9 s static generation. A later warm run reported 5.3 s compilation, 11.3 s TypeScript and 3.1 s static generation. Warm/cache differences prevent attributing those timing differences solely to UI changes.
- Five read-only `select 1` measurements against the configured development database were **2137.4, 474.3, 615.8, 513.1, 509.7 ms**. The first includes connection establishment. Warm median: **511.4 ms**. This is enough latency for sequential session/authorization/query work to be noticeable. Database placement, connection pooling and authorization-query profiling need a separate measured infrastructure exercise; no latency reduction is claimed here.
- A local Laravel `/up` request returned HTTP 200 in **2687 ms** during diagnostics. This is one observation, not a production percentile.
- Frontend baseline: 25 tests passed; expanded suite: 37 tests passed. Test-run duration is not application performance and is not presented as such.
- Each API fetch attempt now has a 30-second deadline; CSRF initialization is a separate attempt. Timeout tests verify that a stalled POST is not replayed and does not sign the user out. Browser control itself also experienced a separate automation timeout; that duration is not treated as an application benchmark.

## Automated verification

| Check | Result |
| --- | --- |
| PHPUnit default isolated SQLite suite | 89 total: 88 passed, 1 PostgreSQL-only concurrency test skipped; 1176 assertions |
| Focused pinned-instructions regression | Passed, 11 assertions |
| Vitest | 9 files, 37 tests passed |
| TypeScript | Passed |
| ESLint | Passed |
| Production build | Passed; final check repeated after reliability changes |
| Laravel Pint on changed PHP | Passed/fixed formatting |
| Git whitespace check | Passed |

Added coverage includes save-race preservation, connection replacement/undo and revision-target synchronization, workspace/logout unsaved protection, service page two, form-load recovery, search focus, submission review/cancellation, V1/V2 palette boundaries, identifier-free summaries, duration presentation, API deadlines, unreadable-success responses and the restriction on replaying unkeyed POSTs after CSRF expiry.

## Browser verification record

- Passed: supported synthetic registration and organization creation; workspace shell and navigation; workflow creation; all eight node types; nine-step/ten-edge configuration through the inspector; required form field; condition rule; seconds-based delay; draft save/reload; server validation; publication confirmation and published version 1.
- Passed: desktop studio in light and dark modes; tablet full-canvas layout and configuration sheet; mobile review mode with publishing disabled; restored normal viewport afterward.
- Passed: Team membership/role explanation view and synthetic manager invitation.
- Request, analytics, task and settings browser results are recorded in the final session notes below.
- Existing dev logs included drag warnings from before this work. Hot reload while changing hook dependencies/node modules produced development-only warnings. Those are not proof of a clean production hydration run; final clean-load diagnostics must be distinguished from earlier hot reload output.

## Remaining technical debt and release checks

1. Run the explicit disposable PostgreSQL concurrency suite before release. Do not point RefreshDatabase-based tests at the hosted database.
2. Complete role-specific browser approval, rejection, revision and assignment completion with a functioning test mail inbox. The configured log mailer redacts invitation tokens; verification and invitation delivery must be exercised through their supported flows rather than bypassed.
3. Profile authenticated session initialization and analytics SQL under representative latency/data volume. The measured development database round trips remain slow; authorization checks were not removed to make pages appear faster.
4. Analytics/audit workflow filter choices still originate from the first page of published workflows. Request discovery is paginated; an extensible historical-workflow picker remains desirable for analytics.
5. The backend notification API has no corresponding workspace-scoped inbox in this frontend. Define safe organization/membership scoping before adding one; do not show mixed-workspace notification payloads in the shell.
6. Assignment reassignment exists on the backend but still lacks a dedicated frontend operator journey.
7. Browser history traversal is not fully intercepted by the draft guard. Anchor navigation, reload/unload, workspace switching and logout are covered. A draft remains guarded when resizing into mobile review mode. A robust App Router history strategy needs separate compatibility work.
8. Optional alignment guides and a guided walkthrough were not added. Snap-to-grid, tidy layout, clear empty states, contextual explanations and keyboard configuration cover the primary redesign.
9. File upload remains unavailable and continues to block forms containing file fields. The redesign does not imply attachment support.
10. End-to-end delivery of notifications and scheduled delay resumption still depends on correctly running queue workers and the scheduler. No new outcomes, fake analytics or fabricated wait estimates were introduced.

## Files and components changed

The complete changed-file inventory follows. Laravel Boost development tooling/guidelines were installed because the pre-existing backend AGENTS.md required that setup; it is unrelated to runtime workflow semantics.

- `backend/AGENTS.md`
- `backend/CLAUDE.md`
- `backend/app/Http/Resources/WorkflowTaskResource.php`
- `backend/boost.json`
- `backend/composer.json`
- `backend/composer.lock`
- `backend/tests/Feature/Workflows/Phase7AutomationNodesTest.php`
- `frontend/app/globals.css`
- `frontend/components/analytics/analytics-screen.tsx`
- `frontend/components/analytics/audit-screen.tsx`
- `frontend/components/dashboard/dashboard-screen.tsx`
- `frontend/components/execution/dynamic-form.tsx`
- `frontend/components/execution/execution-ui.test.tsx`
- `frontend/components/execution/request-create-screen.tsx`
- `frontend/components/execution/request-detail-screen.tsx`
- `frontend/components/execution/request-list-screen.tsx`
- `frontend/components/execution/task-detail.tsx`
- `frontend/components/execution/task-inbox-screen.tsx`
- `frontend/components/patterns/page-header.tsx`
- `frontend/components/settings/settings-screen.tsx`
- `frontend/components/shell/account-menu.tsx`
- `frontend/components/shell/app-breadcrumbs.tsx`
- `frontend/components/shell/app-shell.tsx`
- `frontend/components/shell/app-sidebar.tsx`
- `frontend/components/shell/workspace-switcher.tsx`
- `frontend/components/team/team-screen.tsx`
- `frontend/components/workflows/form-builder.tsx`
- `frontend/components/workflows/node-connections.tsx`
- `frontend/components/workflows/node-inspector.tsx`
- `frontend/components/workflows/node-palette.tsx`
- `frontend/components/workflows/unsaved-changes-guard.tsx`
- `frontend/components/workflows/validation-list.tsx`
- `frontend/components/workflows/workflow-builder.tsx`
- `frontend/components/workflows/workflow-canvas.tsx`
- `frontend/components/workflows/workflow-node.tsx`
- `frontend/components/workflows/workflow-ui.test.tsx`
- `frontend/hooks/use-mobile.ts`
- `frontend/lib/api.test.ts`
- `frontend/lib/api.ts`
- `frontend/lib/execution.ts`
- `frontend/lib/navigation.ts`
- `frontend/lib/workflows/editor-state.test.ts`
- `frontend/lib/workflows/editor-state.ts`
- `frontend/lib/workflows/node-registry.test.ts`
- `frontend/lib/workflows/node-registry.ts`
- `frontend/lib/workflows/types.ts`
- `frontend/next.config.ts`

## Final session notes

- The nine-step/ten-edge synthetic workflow was published as version 1 through the browser after server validation and confirmation.
- Mobile service form verification passed: required-field error summary, focus on the invalid field, entered answer retained, review dialog, and explicit confirmed submission.
- The submitted request reached the real `no_eligible_approver` failure because the manager invitation had not been accepted. The request detail correctly showed Failed, the Approval step, version 1, the submitted answer, and the chronological submission/condition/failure timeline. This was an observed engine result, not a fabricated outcome.
- Request tracking was visually checked on mobile in dark mode. Analytics was inspected on desktop in dark mode and mobile in light mode: it showed one real failed request and suppressed insufficient-sample rates. Changing the date preset kept filters visible and disabled export while results refreshed.
- The audit explorer showed the three real request events. At the mobile viewport, document client/scroll widths were both 375px; the approximately 743px table scrolled inside its own container rather than overflowing the page.
- My Tasks showed its real empty inbox, retained typed search text while changing to Completed, and displayed result-level loading. Approval/assignment/revision action dialogs and historical snapshots passed automated tests, but those role-specific actions were not exercised in a live manager/employee browser session because invitation delivery is blocked by the redacted log transport.
- Settings rendered account/workspace details and theme controls. Unsupported password and notification preferences remained clearly unavailable.
- A clean dark-theme Settings reload had no captured console warnings or errors. A clean studio drag changed the draft to Unsaved changes; Ctrl+Z restored Draft saved; Ctrl+Shift+Z replayed the move; a final undo restored the saved state. No warnings or errors were captured during that drag/undo check.
- The normal browser viewport was restored after device checks. Synthetic QA records were retained; no cleanup deleted application data.
- Final build after the mobile draft-guard check passed: 4.8s compilation, 9.4s TypeScript, 6.3s static generation. The preceding API recovery build also passed (4.9s / 9.7s / 5.5s). These are warm build observations, not a controlled latency benchmark.
- API reliability regression suite: 37 frontend tests passed; the final TypeScript and ESLint commands passed. PHP application changes had already passed the full 88-pass/1-skip suite.
- Browser approval completion, rejection/revision loops, assignment completion, notification delivery and delay resumption remain release verification checks requiring the synthetic role memberships and a working mail/worker/scheduler environment.
