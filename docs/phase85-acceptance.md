# FlowMV Phase 8.5 acceptance — 28 October 2026

## Verdict

Phase 8.5 implementation and local acceptance are substantially complete, but FlowMV is **not production-ready and the Phase 9 release gate is not cleared**. Hosted runtime-role transition, production worker/scheduler supervision, retention approval, full browser-history validation, and the remaining browser/accessibility coverage require closure. No Phase 9 implementation, commit, push, hosted reset, credential change, or published-version rewrite was performed.

Statuses below mean: **Verified** = executed with the stated evidence; **Implemented but unverified** = code/procedure exists without the complete required execution; **Blocked** = a named external/tool/policy prerequisite prevented completion; **Not implemented** = intentionally unavailable or work remains. Automated and browser evidence are identified separately; a passing API test is not described as a browser pass.

## Baseline recovered before fixes

The initial working tree was clean. Baseline Laravel: 89 tests, 88 passed, 1 PostgreSQL-only skip, 1,176 assertions. Baseline Vitest: 37 tests in 9 files passed. ESLint, TypeScript, and the production build passed. The initial PostgreSQL run first inherited hosted TLS settings; explicit local sslmode=disable corrected the harness configuration. Its original lock test then failed because RefreshDatabase left its fixture uncommitted and invisible to the second connection. DatabaseMigrations plus a pre-migration loopback/database-name/server-address guard corrected that false lock result. No hosted migration/reset was used.

Laravel Boost 2.10.3 is in require-dev. Composer's no-dev dry run excludes Boost, MCP, Roster, agent detector, YAML and their development-only dependencies; no vendor packages were removed by that dry run. Composer production audit returned no advisories or abandoned packages. PHP installed here is 8.4.23; deploy the locked dependency set on a compatible PHP 8.4 runtime rather than relying solely on composer.json's older ^8.3 declaration.

The later npm audit identified vulnerable Zod 3.25.76 and development Vitest dependencies. Zod was updated to 4.6.7 with explicit input/output typing for dynamic forms; Vitest was updated to 4.1.12. A normal npm install repaired peer dependencies after npm's transitional peer-graph error; normal npm ci --dry-run succeeded. Full npm audit subsequently returned zero advisories. Relevant sources: [Zod advisory](https://github.com/advisories/GHSA-mrhx-c7xc-m3w5), [Zod migration guidance](https://zod.dev/v4/changelog), [Vitest 4 migration guidance](https://v4.vitest.dev/guide/migration).

## Fixes

- Notifications are constrained by authenticated recipient, active workspace membership, and the actual workflow instance's organization. Legacy payloads resolve through their instance; a payload's organization display field is not trusted. Both compatibility and explicit organization routes enforce this scope. PostgreSQL casts Laravel's text data column to jsonb and compares the extracted identifier to UUID-as-text; malformed instance identifiers do not trigger UUID casts. SQLite retains its JSON-path query. Actual PostgreSQL endpoint tests cover both routes, idempotent mark-read, read/unread filters, 51-row pagination, forged organization fields, cross-recipient/cross-workspace access and revoked membership.
- The shell exposes a workspace notification dialog. It fetches on open, paginates 50 notices at a time, filters All/Unread/Read, marks read through scoped routes, and remounts on workspace changes so previous results cannot appear in the next workspace. Automated UI regressions and the populated PostgreSQL browser dialog/read/filter interaction pass; actual multi-page browser data remains unverified.
- Durable notification delivery uses deterministic in-app notification IDs, current-membership checks, bounded SMTP timeout, attempt limits, and claim ownership checks. Delivery status and its audit event commit together. An expired claim is recovered after five minutes; repeated scheduler enqueues are safe. An obsolete worker cannot overwrite a newer claim's status.
- workflow:recover-notifications is scheduled every minute alongside the existing delay recovery command. In-app persistence is idempotent. External SMTP delivery remains **at least once**: a crash after the provider accepts a message but before database commit can resend it. No exactly-once email guarantee is made.
- Administrators can open an assignment from request detail, select an active member, and submit a keyed reassignment. Task-detail action capabilities come from Laravel so readable tasks do not advertise actions the viewer cannot perform. Backend transactions and authorization remain authoritative. Failed approval dialogs now close to expose their error message; retries retain the operation's idempotency key.
- Analytics/audit workflow choices use lazy, searchable server pagination, including workflows beyond page one. Initial analytics avoids one workflow-list request; audit avoids the same extra request. Tests exercise page-two selection and lazy loading.
- The workflow guard listens for cancelable Navigation API history traversal in addition to existing anchor, unload, workspace-switch, and sign-out protection. Unsupported/noncancelable history paths still need review; no blanket browser pass is claimed.
- User organization summaries use one join retaining active-membership filters, removing one SQL round trip from login and /me without introducing permission caching.
- Retention has a count-only command and explicit disabled --apply path. The reviewed procedure and required approvals are in submission-snapshot-retention.md. Attachments remain execution-blocked.

## Isolated QA environment

PostgreSQL 18 runs on 127.0.0.1:55485 in ignored backend/storage/app/phase85-qa/postgres. `flowmv_phase85_qa` contains the preserved synthetic browser workspace. **Only `flowmv_phase85_test` is disposable and used by destructive test setup.** The guard checks the actual connected database and loopback server address before migrations.

Mailpit runs locally at 127.0.0.1:18025, SMTP 127.0.0.1:11025, with recipients restricted to example.com and no forwarding/relay. Its database and tools remain ignored. All three users registered through the UI, verified using captured signed email links, and joined QA85 Acceptance Workspace through supported invitation acceptance. No email_verified_at was set directly. Passwords were generated in memory and are absent from source and this report.

The simultaneous application sessions use separate loopback origins/cookie hosts: Admin localhost:3001/API8001, Manager 127.0.0.1:3002/API8003, Employee 127.0.0.2:3003/API8004. They share only the local QA database. These are independent application sessions, **not separate browser profiles**. Chrome control was unavailable and native-app approval timed out. Invitations were opened at the corresponding QA frontend origin while preserving the exact captured token; verification used each account's original signed link.

Laravel serve must use --env=phase85 **and --no-reload**, with APP_ENV=phase85 exported for child processes. The ignored .env.phase85 contains only local QA settings and a fresh application key. Reload-enabled serve dropped those settings in its child, causing default-development CORS/session responses before registration could proceed; stale port-8001 listeners were removed and explicit QA CORS was verified. Do not run QA artisan commands against the default hosted environment.

The QA frontends use separate ignored .next-phase85* outputs and configurable proxy cookie names. Defaults for ordinary development remain unchanged. The original localhost:3000/8000 services and user tabs were preserved. Multiple QA frontends plus a 23-worker production build exhausted available RAM during verification; extra QA frontends were stopped temporarily and checks serialized. A resource-pressure timeout is recorded as a failed run until a later rerun actually passes.

On 28 October the existing database sessions had expired. Generated passwords were held only in browser automation memory, which was reset, and are not recoverable from the one-way database hashes. No supported password-reset endpoint/UI was found. Restoration of the existing sign-ins was requested, and the user said they would restore them. No authentication bypass, database password rewrite or direct verification update was used. The original accounts, published workflow and both completed requests remain intact.

Using the user's earlier authorization to register another synthetic account when necessary, a separate **QA85 Continuation Checks** workspace was created through the app. Its continuation administrator registered and verified through Mailpit. A new minimal Start → Notification → End workflow, **QA85 Notification Acceptance**, was configured, saved, validated and published through the UI. Its actual browser submission completed and a real QA queue worker delivered two outbox rows (in-app and SMTP). This avoids recreating the original three-role journey and provides fresh signed-in evidence for the notification fix, undo/redo and appearance checks. Original Scenario B's historical browser screen still requires original-account sign-in restoration.

QA frontends were stopped during heavy checks. After the successful build, only the Admin frontend on localhost:3001 was restarted. QA API, PostgreSQL and Mailpit processes remain separate from the original development services. The viewport override initially left hidden tabs at 1280×720; presenting the QA browser allowed the requested dimensions to apply, and actual DOM dimensions were checked before accepting responsive results.

Evidence identifiers retained for continuation:

- Original eight-node published workflow: c9af1c06-ace1-4a34-9dec-409f4f27e20d, immutable version 1.
- Original completed Scenario A request: 3556be3f-28a3-435e-971c-84f6b138197a.
- Original completed Scenario B request: 146cf0fd-d407-4cc8-bd9b-0b3ad7730498.
- Continuation workflow: 00c9c44d-a869-44fa-a3ef-f62579b9dc94, published version 1; actual completed request: 1f56f5a0-c4c8-4a80-8818-9bf181e8a241.

Local ignored visual evidence is retained under backend/storage/app/phase85-qa/notification-read-browser.jpg and mobile-builder-browser.jpg. Mailpit visibly contains the new continuation delivery in addition to the original Manager/Employee emails. After these checks, the temporary continuation account was signed out through the normal app menu and the Admin QA sign-in page was left ready for the user to restore an original account. The viewport override was reset.

## Browser acceptance matrix

| Requirement | Status | Executed evidence / limit |
| --- | --- | --- |
| Admin, Manager, Employee registration, verification and invitations | Verified | Actual browser forms; Mailpit verification and invitation emails; 3 active team members |
| Simultaneous role sessions without cookie interference | Verified | Separate loopback cookie hosts remained signed in concurrently |
| Separate browser profiles | Blocked | Chrome connector unavailable; native-app approval timed out; loopback-origin isolation used instead |
| All eight nodes added/configured/connected in builder | Verified | Nine steps including two End nodes; 10 connections; all types configured through inspector |
| Save, reload, validate, publish | Verified | Reloaded stored draft, backend validation passed, immutable version 1 published |
| Scenario A submission and approval | Verified | Employee supplied synthetic leave reason; Manager reviewed and approved; actor recorded |
| Scenario A assignment/reassignment | Verified | Admin reassigned role task explicitly to Manager; Manager completed it; audit event preserved |
| Scenario A notification and delay to completion | Verified | Actual queue/Mailpit delivery; worker exited with pending delay; scheduler/restarted worker completed once; Employee viewed Completed |
| Scenario B rejection and revision | Verified | Manager rejected; Employee received Revision task, edited values, resubmitted same instance; a new approval showed corrected values |
| Scenario B final completion and decision snapshots | Blocked (final browser screen); Verified (database evidence) | Read-only QA query confirms Completed; four snapshots retain original initial/rejected values and corrected revision/approved values. Final terminal screen and historical-snapshot dialog need restored sign-in |
| Immediate role-change enforcement | Verified | Admin demoted synthetic Manager while stale approval UI remained open; backend refused action with Not found; request stayed awaiting approval; role restored through UI |
| Self-approval, revoked access, cross-organization access, stale 409, duplicate keyed submission | Verified | Existing Laravel security/idempotency tests and PostgreSQL races; not all repeated in browser |
| In-app notification dialog and mark-read | Verified | Continuation browser displayed genuinely delivered notice; Unread filter showed it, mark-read removed it from Unread, Read filter retrieved the persisted Read marker. Mobile dialog fit. No PostgreSQL SQL error |
| Notification pagination | Verified (PostgreSQL/UI regressions and browser boundary); Implemented but unverified (live page-two browser) | PostgreSQL endpoint tested 51 scoped notices across two pages; UI regression exercised Next and filter reset; actual one-notice browser showed Page 1 of 1 with both boundary controls disabled. No 51-notice live browser fixture was generated |
| Analytics/audit workflow pagination | Verified | UI regression selects page two, lazy-fetch request count asserted; large live browser catalogue not seeded |
| Undo/redo | Verified | Continuation builder: Tidy marked dirty, Undo restored Draft saved, Redo restored Unsaved changes |
| Unsaved browser Back/Forward | Verified (cancelable paths); Implemented but unverified (complete protection) | Fresh edited draft prompted on Back; dismiss retained editor; a fresh edit plus accept left the editor; Forward restored saved draft. A repeated programmatic Back after dismissal navigated without a second prompt. The [HTML standard](https://html.spec.whatwg.org/dev/nav-history-apis.html) explicitly allows noncancelable repeat traversals after a prevented navigation; this is a browser limitation, not evidence of a Next.js defect. Additional draft-loss protection for those paths is not implemented; no blanket pass |
| Light/dark and responsive builder smoke | Verified (specified viewports) | Desktop 1280×720 builder observed in both themes; measured 1024×768 tablet showed Nodes/Properties controls and opened Properties drawer; 390×844 mobile showed intentional read-only preview, disabled publishing, no document horizontal overflow. Both themes observed at tablet/mobile sizes; theme persisted through reload |
| Remaining responsive role journeys | Implemented but unverified | Mobile populated notifications and analytics rendered without page-level horizontal overflow; exhaustive request/task/approval/revision screens across all roles and themes not completed |
| Full accessibility audit | Not implemented | Semantic controls and dialog behavior tested; screen-reader, keyboard-only full journeys and formal contrast audit not completed |

## Eight-node compatibility/execution matrix

| Node | Builder/save/reload/validate/publish | Runtime evidence |
| --- | --- | --- |
| Start | Verified | Actual browser submissions created pinned-version instances |
| Approval | Verified | Manager approval, rejection, new post-revision task; concurrent approval test |
| Condition | Verified | Stored is-not-blank rule executed on browser submissions; API tests cover true/false/operator validation |
| Revision | Verified | Employee browser edit/resubmission; prior completed decision retained; exhaustion covered by API tests |
| End | Verified | Completed End reached in browser; Rejected End configured/published and covered by API tests, not reached in browser |
| Notification | Verified | Durable outbox, actual worker, in-app rows, SMTP messages visible in Mailpit; failure/retry/recovery API tests |
| Assignment | Verified | Manager role assignment, administrator reassignment, authorized completion in browser; revoked/unauthorized API tests |
| Delay | Verified | Persisted ten-second resume_at, worker stopped/restarted, scheduler fallback, repeated wake-up idempotence |

V1 compatibility remains covered by test_v1_published_graphs_keep_executing_without_reinterpretation. New publishes stamp registry V2. Existing graph contracts and published-version pinning were not changed. Attachments remain explicitly unsupported: frontend blocks file forms and backend refuses execution. Secure private upload/retrieval, scanning/quarantine, retention, and authorized downloads are **Not implemented**; no public-upload workaround was added.

## PostgreSQL and database security

The suite now exercises committed fixtures across independent processes/connections: simultaneous approvals produce one decision and one active successor; duplicate keys return the same instance and one start event; concurrent last-admin releases retain one admin; row locks/open-task uniqueness block a second session. The permission test creates a temporary NOLOGIN runtime role in the disposable cluster, runs actual start/approval under checked-in table/sequence grants, refuses immutable UPDATE/DELETE and schema DDL, and proves child snapshot cascade without child DELETE privilege. It removes its temporary role/grants afterward.

The existing event trigger rejects organization deletion including cascades. This behavior is preserved; blanket claims that organization deletion currently purges all audit/snapshot rows are incorrect. Runtime does not receive direct snapshot DELETE.

Exact runtime/migrator instructions, ownership transfer scope, future-grant review, functions/triggers, PUBLIC/schema review, TLS, DB_URL precedence and manual credential rollout/rollback are in database-access.md and backend/database/runtime-permissions.sql. Hosted roles/credentials were not changed. **Production application credentials, effective grants, migration-role deployment, RLS/schema isolation and secret rotation remain Blocked pending the manual production transition and staging verification.**

## Performance evidence

These are actual samples, not a load-test SLA. Local request samples use Laravel's HTTP test kernel on disposable PostgreSQL with database sessions and capture only SQL counts/time, never SQL text/bindings or submission values. Kernel samples exclude TCP and per-process bootstrap. Initial and warm samples are distinct; a warm-cache difference alone is not credited to a fix. The baseline predates the membership-summary join; the later profile below was collected during a resource-constrained test run with different warm-up conditions. These are not controlled paired performance benchmarks. Only the repeatable SQL-count reduction is credited to that code change.

| Endpoint | Before ms (first / warm) | After ms (first / warm) | SQL before → after |
| --- | --- | --- | --- |
| /api/v1/me | 11.95 / 6.26 | 12.09 / 11.32 | 5 → 4 |
| Workflow listing | 17.07 / 13.04 | 54.21 / 24.49 | 11 → 11 |
| Workflow detail | 18.29 / 11.94 | 30.88 / 27.76 | 12 → 12 |
| Request submission | 85.82 / 29.57 | 145.94 / 59.46 | 27 → 27 |
| Task inbox | 17.7 / 12.86 | 28.9 / 20.09 | 7 → 7 |
| Analytics | 37.84 / 28.16 | 359.18 / 53.43 | 21 → 21 |
| Audit | 22 / 19.79 | 41.33 / 33.81 | 13 → 13 |
| CSRF initialization | 3.94 | 9.44 | 2 → 2 |
| Login/session initialization | 275.54 | 1603.19 | 8 → 7 |

Read-only hosted PostgreSQL probe: connection 1,327.64 ms; five warm SELECT 1 round trips 366.11, 363.72, 363.47, 362.55, 364.78 ms (median 363.72 ms). No application data or credentials were printed and no writes were issued by this probe.

Separate real HTTP CSRF probes against the local QA PHP server returned 204 in 1,325.41 / 1,110.39 / 1,143.79 ms sequentially. Three concurrent requests to the same single PHP development server returned in 1,059.29 / 2,217.61 / 3,254.32 ms, showing serialized service/queueing. This includes TCP, PHP boot and framework work; it is not the kernel-only table above. CSRF kernel profiling uses two session-related SQL statements; its measured database time is only around a millisecond locally. SQL counts exclude driver-internal BEGIN/COMMIT protocol messages, so they are not a packet-level wire trace.

The only backend query reduction credited here is login 8→7 and /me 5→4. Other timing changes are sample/cache/resource variation, not claimed optimizations. UI filter loading removes one initial list request from analytics and audit, verified in the request mocks/source behavior; a full browser waterfall/rendering profile and a production-topology load test are outstanding. Co-locate PHP and PostgreSQL, use a supervised PHP worker pool instead of artisan serve, and measure sessions, connection pooling and request waterfalls in staging. Do not disable CSRF, tenant checks, or permission checks to hide RTT.

## Queue/scheduler evidence and deployment limits

For the first browser request, queue:work --once delivered four outbox rows; Mailpit visibly received the Manager and Employee SMTP messages. The worker then exited while the delay remained pending and one queue job remained. schedule:run executed both registered recovery commands. A restarted worker consumed the old delay job harmlessly after scheduler recovery. Repeated delay/notification recovery plus another worker run yielded: one consumed delay, one delay_resumed event, one instance_completed event, four notification_sent events, one task_reassigned event, four sent outbox rows. No direct delay/state edits were used for this browser outcome.

Latest read-only browser-QA database check: the original two requests remain Completed with four sent outbox rows each; the continuation request is Completed with two sent rows. There were zero failed queue jobs at the final original-request check. Human task actions retain their actual actors. Automated events keep the existing engine convention of attributing them to the submitter; a dedicated system-actor presentation was not added.

Regression tests separately exercise lost-claim recovery, in-app deduplication, revoked recipient refusal, retry exhaustion/provider failure and repeated jobs. These controlled test fixtures are not represented as real browser worker-crash tests. External SMTP may duplicate across a provider-accept/database-commit crash window. Production must supervise workers with timeout/retry_after/lease ordering (current job timeout 60 seconds, database retry_after must exceed it, recovery lease five minutes), run Laravel's scheduler every minute, keep the mail transport bounded, monitor failed jobs/outbox failures and alert on recovery backlog. Production supervision and hard-kill/failover behavior are not verified here.

## Retention and attachments

Retention dry-run and refusal of destructive --apply are Verified by regression tests. Policy approval, legal-hold rules, maintenance-role deletion, durable maintenance logs, backup/export expiry and an authorized cleanup schedule are Blocked/Not implemented as detailed in submission-snapshot-retention.md. No records or development mail artifacts were silently purged.

File attachments are unsupported and execution remains blocked. A future solution requires private storage, size/type validation, tenant authorization on retrieval, quarantine/scanning and an approved retention policy. No insecure public upload was added.

## Reproduction and safe continuation

Run ordinary Laravel tests with the repository's SQLite test configuration. Run PostgreSQL acceptance only after verifying the explicit disposable local target:

```powershell
# backend directory; inspect the result before running the destructive suite
& 'C:\Program Files\PostgreSQL\18\bin\psql.exe' -h 127.0.0.1 -p 55485 -U postgres -d flowmv_phase85_test -c 'select current_database(), inet_server_addr()'
php .\vendor\phpunit\phpunit\phpunit -c phpunit.pgsql.xml --no-progress
```

The checked-in configuration fixes loopback, port 55485, database flowmv_phase85_test, sslmode=disable, and its matching explicit target marker. This local disposable cluster uses loopback trust authentication only; do not copy that setting to production. A different test target must retain the pre-migration safety checks. Do not change DB_URL/host/name to hosted Supabase for this suite.

Start the Admin API using the ignored QA environment:

```powershell
$env:APP_ENV='phase85'
php artisan serve --env=phase85 --no-reload --host=localhost --port=8001
# Separate process, frontend directory
$env:NEXT_PUBLIC_API_URL='http://localhost:8001'
$env:FLOWMV_DIST_DIR='.next-phase85'
$env:FLOWMV_SESSION_COOKIE='flowmv-phase85-session'
npm run dev -- --hostname localhost --port 3001
```

Manager API process overrides FRONTEND_URL=http://127.0.0.1:3002, APP_URL=http://127.0.0.1:8003, SESSION_COOKIE=flowmv-phase85-manager, SANCTUM_STATEFUL_DOMAINS=127.0.0.1:3002,127.0.0.1:8003; serve on 127.0.0.1:8003 with --env=phase85 --no-reload. Its frontend uses that API URL, matching proxy cookie, output .next-phase85-manager and host/port 127.0.0.1:3002. Employee uses the corresponding 127.0.0.2:3003/API8004, cookie flowmv-phase85-employee, output .next-phase85-employee. Keep environment overrides per process, never in the hosted .env. QA worker/scheduler commands must also export APP_ENV=phase85 and use --env=phase85.

Start the existing PostgreSQL cluster with postgres.exe -D backend/storage/app/phase85-qa/postgres -h 127.0.0.1 -p 55485; do not initdb again. Restart the existing Mailpit executable with its existing database, loopback listeners, example.com recipient restriction and forwarding disabled. Do not reset either browser QA database or mail catcher to continue acceptance. Do not add synthetic credentials to this document.

## Final check ledger

Checks are serialized against the current working tree; no QA frontend runs during them. The ordinary suite intentionally skips PostgreSQL-only tests; the separate PostgreSQL run executes those tests against flowmv_phase85_test.

| Check | Final executed result | Scope / command |
| --- | --- | --- |
| Laravel | Verified: 92 passed, 7 skipped, 1,247 assertions; 115.39 s | Ordinary SQLite suite, 99 total tests; PostgreSQL cases are the seven skips |
| PostgreSQL | Verified: 7 passed, 94 assertions; 27.531 s | php vendor/phpunit/phpunit/phpunit -c phpunit.pgsql.xml --no-progress; guarded disposable local database only |
| Vitest | Verified: 43 passed in 10 files; 34.52 s | npm test -- --reporter=dot --maxWorkers=1 |
| ESLint | Verified: exit 0 | npm run lint |
| TypeScript | Verified: exit 0 | npm run typecheck |
| Production build | Verified: exit 0; 22/22 static pages | FLOWMV_DIST_DIR=.next-phase85-build, FLOWMV_BUILD_SINGLE_WORKER=1, npm run build; compile 14.2 s, TypeScript 18.0 s, static generation 2.5 s |
| Laravel Pint | Verified | Dirty-file formatting check completed after backend changes |
| Dependency review | Verified | Composer production audit: no advisories/abandoned packages; full npm audit: zero advisories; npm ci --dry-run passed |

A failed intermediate run is not a pass. Invalid Testing Library options, missing JSDOM pointer APIs and a Vitest mock accidentally returned as hook cleanup were corrected and subsequently rerun. The expanded PostgreSQL test initially called a nonexistent helper; the corrected full suite passed. An overloaded earlier UI run timed out; the serial full run above passed. The Vite warning about a future native config-loader default remains a non-failing configuration migration notice.

## Changed-file summary

All 43 changed/new files remain uncommitted. The current work groups are:

- **Notification scope and delivery:** NotificationController, workflow notification payload/executor, ProcessNotificationOutbox, RecoverWorkflowNotifications, mail timeout, API/scheduler routes, and Phase85NotificationReliabilityTest.
- **PostgreSQL, permissions and retention:** phpunit.pgsql.xml, WorkflowConcurrencyTest, runtime-permissions.sql, SnapshotRetention command/test, database-access.md and submission-snapshot-retention.md. No hosted migration or role change was performed.
- **Authorization and performance:** User organization-summary join, WorkflowTaskResource capabilities and Phase7AutomationNodesTest.
- **Frontend journeys:** notification dialog/tests and shell integration; assignment reassignment dialog, task/request screens, execution types/tests; lazy workflow filter and analytics/audit screens/tests; unsaved-history guard/tests.
- **Dependency/test/QA configuration:** package.json/lock, Vitest setup, Next output/single-worker flag, session-cookie proxy option, TypeScript generated-output includes, ESLint and git ignores. QA environment, PostgreSQL files, Mailpit state and result logs are ignored local artifacts.
- **Acceptance evidence:** this report records executed outcomes and remaining gates; the immutable published version and existing QA requests were retained.

## Security and release gates

| Capability | Status | Evidence / remaining gate |
| --- | --- | --- |
| Sanctum sessions and CSRF | Verified (local tests and real sign-in journeys) | Session/CSRF middleware retained, local isolated-cookie logins exercised; production cookie/TLS/origin deployment still requires staging verification |
| Organization isolation and action authorization | Verified (automated); partial browser coverage | Laravel/PG checks, recipient/workspace notification denial and actual stale-UI demotion refusal; remaining Scenario C browser cases blocked by expired sign-ins |
| Assignment visibility and reassignment | Verified | Server capabilities, actual Admin reassignment and Manager completion, UI regressions |
| Published-version and submission protections | Verified | V1 compatibility test, pinned immutable version 1, protected-table PG tests; original and corrected Scenario B decision snapshots confirmed by read-only query |
| Durable notification/delay recovery | Verified (local); production operation unverified | Worker/scheduler/Mailpit and recovery regressions; supervised deployment, hard-kill/failover and backlog alerts not exercised |
| Production least-privilege runtime role | Blocked | Grants tested locally; manual hosted credential/ownership transition and staging effective-privilege verification remain |
| Sensitive-data handling | Verified for reviewed changes | No generated passwords, signed email tokens, SQL bindings, hosted secrets or submission values were added to reports/logging. Snapshot payload remains hidden by default and permission-scoped when displayed |
| Attachment execution | Not implemented | Intentionally blocked rather than exposing public uploads; keep outside release scope until secure storage/retrieval/scanning exists |
| Approved retention and destructive cleanup | Blocked / Not implemented | Count-only safety verified; policy/holds/backups and approved maintenance implementation remain outstanding |

**Phase 9 verdict: not technically cleared.** The populated notification/read-filter browser test, undo/redo and measured builder theme/responsive smoke have now passed. Close final original Scenario B screen/history inspection, remaining Scenario C browser cases, repeated/noncancelable history navigation and the remaining responsive role journeys before closing Phase 8.5. Restore existing synthetic account sign-ins through the supported app flow; do not mutate credentials or authentication flags to force a pass. Complete keyboard/screen-reader/contrast acceptance to the agreed release standard.

Before production deployment, additionally perform the documented restricted-runtime/migrator credential rollout with staging TLS/cookie/origin verification; supervise PHP, queue and scheduler with timeout/lease ordering and monitoring; verify backup restoration and approve retention scope. Profile the production topology under representative concurrency. Attachments remain unavailable unless separately implemented and accepted. No production credential changes or Phase 9 work were performed in this phase.
