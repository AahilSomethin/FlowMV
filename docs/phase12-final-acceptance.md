# Phase 12 — final engineering acceptance

**Engineering acceptance: completed for the verified local and simulated scope. Production: NOT CLEARED.** Evidence date: 2026-11-05. Live providers, independent security/accessibility assessment and production operational gates remain open. No deployment, commit, push, hosted reset, production-key change, paid call or real message was performed.

## 1. Initial findings

The initial working-tree/hash inventory preserved 693 existing project-owned files. Review covered the Next 16.4.3/React 19.3 frontend, Laravel 13/PHP 8.4 backend, Go 1.27.3 service, PostgreSQL schema, V1/V2/V3/V4 registries, authentication/RBAC, execution/jobs, integrations, document/AI boundaries, launchers and documentation. Existing uncommitted work was retained. Seven historical acceptance/security reports selected from the initial inventory still match their original SHA-256 hashes. Missing earlier historical reports were not reconstructed.

| Priority | Confirmed finding | Resolution |
| --- | --- | --- |
| High release risk | Eight Composer advisories across five Symfony packages | Compatible patch updates; final audit zero. Applicability differs; no exploit was claimed. |
| Medium | Private responses lacked uniform no-store | API middleware and browser fetch policy, with regression coverage |
| Medium | Workspace state keyed only by organization | User/organization/role remount; visible-session refresh; stale async session responses ignored |
| Medium | Go health used a hardcoded plaintext listener; shutdown could exit before draining | Configured-port certificate-verifying health and awaited graceful shutdown, with held-request/TLS/race tests |
| Medium | Production deployment/recovery existed only as proposed guidance | Separate local TLS/FPM/restricted-role candidate, health checks, restart and database restore rehearsal |
| Medium | Default visible-link prefetch was broad | Bounded intent/likely-next route-shell policy; no private-data cache |
| Low | Redundant CSRF bootstrap and analytics aggregate | Cookie/in-flight reuse with safe 419 renewal; one authorized query removed per unfiltered report |
| Low | Unused shadcn generator pulled dependency findings | CSS retained with MIT notice; generator removed; final npm audit zero |
| Accessibility | Missing auth heading/status semantics and weak selected input/destructive states | Semantics, opaque surface contrast fixes and automated/manual checks within the scope below |

Implementation verification also caught and corrected a staging runtime image missing `next.config.ts`, and a native verification build made without its compiled demo API URL. Neither incident changed records or credentials. A Windows sandbox temporary-cache rename prevented one Vitest attempt; the permitted retry passed. A read-only query using the wrong task table name was rejected, then corrected.

## 2. Cleanup and maintainability

Heavy analytics charts moved into a lazy-loaded module with a failure boundary, fixed plot height and disabled animation. Navigation speculation is centralized in `IntentLink` and a pure budget/path/network policy. Authentication generations prevent an old 401 expiring a newer login or a refresh resurrecting a logged-out session. CSRF 419 always renews the token for future use; unkeyed POST never automatically replays. A sibling local profile overwriting XSRF is covered.

Laravel retains its domain actions, transactions, live policies and queues. No permission check was removed for speed. Go retains bounded advisory processing; no additional Laravel responsibility moved to Go. Runtime deployment checks expose safe findings rather than secrets. Native launcher shutdown stops only its owned child trees and preserves PostgreSQL. Functioning security/compatibility/migration services were not deleted for cosmetic simplification. Large provider services remain domain-specific; further decomposition needs a concrete benefit.

## 3. Removed or consolidated files/dependencies

- `frontend/vitest.config.ts` became `vitest.config.mts`, using `import.meta.dirname`; this removes the loader warning without changing coverage.
- Recharts rendering moved out of `analytics-screen.tsx` into `analytics-charts.tsx`; the screen retains data/permission/error handling.
- shadcn CLI dependency removed. Its required 4.23.0 stylesheet is retained as `frontend/app/shadcn.css`, with full upstream MIT attribution in `frontend/THIRD_PARTY_NOTICES.md`.
- Compatible Symfony patch lockfile updates and a targeted minimatch 11.0.4 override; no framework major migration. Development-only axe-core is pinned at 4.14.0.
- No historical report, QA database, migration, immutable snapshot or secret file was deleted. Generated diagrams/builds/private evidence remain ignored. No FlowMV project license was invented.

## 4. Performance baseline and method

Host: shared Windows Intel Core i7-14650HX, 16 GiB RAM; Node 22.19.0, PHP 8.4.23, Go 1.27.3, local PostgreSQL 18 on the owned native 55490 target. Docker Desktop Linux engine 29.5.3 was verified. All workloads use synthetic inputs and bounded concurrency, without paid providers or injected provider delay. Raw evidence is ignored under `backend/storage/app/phase12-audit` and existing guarded profiler directories.

Initial HTTP: 20 successful samples per endpoint, concurrency two, normal session/RBAC/rate controls, zero throttles. Requests p50/p95 1629.346/3458.761 ms; analytics 1691.554/2425.615 ms. PHP development-server serialization is included. These are not production throughput or remote-database measurements. Other endpoints were reviewed and functionally covered; they did not receive equivalent large-sample latency profiles. No p99 is reported.

## 5. Frontend before/after

Twelve routes have one process-cold and three warm HTTP samples, plus initial-script counts and raw/estimated gzip bytes. Final Analytics initial JavaScript fell **1,313,224 to 949,706 bytes** (about 27.7%); estimated gzip 403,216 to 299,299 bytes. Recharts is deferred until authorized report data exists, not eliminated. The modeled serial 120 ms RTT/256 KiB/s cost fell 3876.66 to 3479.05 ms; this is a model, not measured throttled-browser navigation.

Final warm Analytics HTML median was 13.09 ms versus 12.49 ms: no server latency gain. Builder initial bytes increased 1,281,171 to 1,283,461, reflecting common shell overhead. Browser hydration CPU, actual client-transition latency and prefetch wire counts are unavailable through the current read-only browser surface. They remain measurement limitations, not fabricated improvements. The [frontend performance guide](performance/frontend-loading-and-prefetch.md) contains all twelve route comparisons and exact commands.

## 6. API, SQL and PostgreSQL evidence

Unfiltered analytics reuses an already authorized aggregate. Service queries fell 16 to 15 per call; HTTP regression counts 19 to 18 including middleware. Across 40 measured calls after three warmups, queries fell 640 to 600. Before/after p50/p95 was 31.3099/34.3389 versus 49.554/64.2894 ms; SQL totals 745.86 versus 1215.98 ms. Other shared-host workloads slowed too. **Only the query-count reduction is demonstrated; latency improvement is not established.**

Read-only staging plans selected existing organization indexes: one-row request page 1.396 ms/eight buffer hits; empty task page 0.214 ms/seven hits; zero lock waiters at inspection. These tiny local samples do not justify index/write overhead, a pooler or capacity claims. No Phase 12 migration/index was added. All 18 existing migrations ran forward on fresh owned staging; repeated prepare reports nothing to migrate. Guarded PostgreSQL concurrency and least-privilege tests passed. See [backend evidence](performance/backend-optimization.md).

## 7. Go, queue and AI evidence

All 46 Go tests across seven packages, vet, formatting and Linux race checks passed. REST/signature/replay/schema/cancellation/concurrency/failure tests remain covered. Healthcheck verifies custom-port TLS; partial TLS settings fail startup. Shutdown drains active requests before exit. Staging proved Laravel HMAC + configured-CA TLS extraction with deterministic parity and zero provider calls.

Bounded 80-sample signed HTTP extraction: PHP p50/p95 0.1151/0.2318 ms, 7404.733 operations/s; Go including readiness/signing/JSON/HTTP 8.2023/36.3874 ms, 54.281 operations/s. All matched, zero SQL/provider calls/throttles. Existing PHP extraction remains; no migration win is claimed. Current microbenchmarks are recorded separately in the backend guide.

Forty real queued DOCX jobs, one worker, simulated scanner: 40 completed in 1756.877 ms, 22.77 jobs/s; processing p50/p95 6.0484/12.7646 ms; queue wait 1253.8011/1477.1891 ms. Worker CPU 203.125 ms; peak PHP allocated memory 35,651,584 bytes, not OS RSS. No domain/audit records or provider calls. Queue waiting is distinct from processing.

OpenAI Responses and Gemini Interactions contracts were rechecked against official references on the evidence date. Existing fixed hosts, model/operation policy, minimization, reservations/budgets, bounded output schemas, provenance and human-review rules remain. No paid inference/content-quality/billing/retention verification occurred. AI proposes; Laravel and eligible humans decide.

## 8. Prefetch and cache acceptance

One likely next shell after 700 ms: Employee Requests, Manager Tasks or Admin Workflows from Overview; Analytics prepares Audit. Hover intent waits 120 ms; keyboard focus prepares immediately. Leave/blur/unmount/scope change cancels pending timers. Maximum eight unique safe paths/minute, partitioned by user/organization/role. Offline, Save-Data and slow 2G/3G suppress speculation. Queries, OAuth callbacks, document routes and unknown providers are excluded. Started Next prefetches cannot be aborted through its supported API.

Tests cover budget, paths, hints, focus/hover/cancellation, scope changes and API/session races. Next reuses route shells; no private business data/query library or authorization cache was added. Logout/unmount clears policy metadata, and scope changes remount private views. Every API call and job reauthorizes on the server; task outcomes and external delivery are never optimistically confirmed.

## 9. Security disposition

Final Composer/npm audits reported **zero advisories**; Go govulncheck v1.9.0 reported no known reachable vulnerabilities. Audits are date-bound. Negative suites cover tenant isolation, stale membership/task/grant state, personal credential privacy, vault generation/rotation, webhook/OAuth/replay attacks, bounded document handling, AI policy/budget races and immutable published execution. No unrestricted HTTP node, arbitrary provider base URL or model tool authority was introduced.

Candidate boot rejects unsafe production settings, logging mail, reused/invalid vault or service keys, simulation, insecure cookies/URLs, inadequate queue lease and unverified database TLS. Long-running services receive no migrator secrets; Go receives no DB/vault/app keys. Logs exclude provider bodies, credentials and document/message text. See [security review](security/final-security-review.md). Automated tests are not an independent penetration test.

## 10. Accessibility acceptance and limits

Thirty selected opaque token pairs across light/dark meet tested text 4.5:1 or control-boundary/focus 3:1 thresholds using OKLCH-to-linear-sRGB luminance. Inputs use opaque surfaces; destructive controls use card/muted/accent surfaces rather than contrast-reducing tints. Automated axe checks cover auth/errors and an actual Radix dialog, with jsdom color checking disabled; token tests are separate. This is not an all-states contrast audit.

Browser verification: auth h1/required-field focus/error alerts; Telegram revoke dialog password autofocus, reverse-tab trapping, Escape and restored Revoke focus; mobile navigation dismissal; both themes; 320/390 reflow; tablet/desktop; role-limited task/builder/connection controls. Charts disable animation and the existing reduced-motion CSS remains.

Unverified: actual screen-reader audio/session, effective browser 200% zoom, reduced-motion emulation, every component/state and comprehensive React Flow keyboard/nonvisual editing. A 320px viewport is not a zoom test. Mobile sidebar can remain open after navigation and may restore focus to the document after a route change; Escape dismisses it. Treat this as remaining navigation/accessibility debt. No blanket WCAG 2.2 AA certification.

## 11. Native and Docker preservation

| Target | Preserved verification |
| --- | --- |
| Native `flowmv_phase10_demo`, local 55490 | Original 24 users/eight workflows/100 historical requests, 110 total; ten AI scenarios; composed request completed with three advice runs, three accepted simulated email actions and one decision snapshot |
| Docker owned demo project | Same original 24/eight/100, 108 total; ten AI scenarios; independent completed composed request and one decision snapshot |

Native owned-process stop released its application ports, and corrected production build/start succeeded on 3010/8010/8020. Docker Linux rebuild/start preserved named volumes and initializer verified existing records. Both use existing local keys and ordinary login. No destructive reset/reseed was used. Phase-specific QA guards/profiles were preserved; PostgreSQL profiling artifacts were backed up/restored with matching hashes. Historical report hashes remain unchanged.

## 12. Production candidate configuration

Implemented separately from demo: PHP-FPM/opcache, Next production runtime without dev packages, private TLS Go, restricted runtime PostgreSQL role, explicit owner/migrator profile, supervised worker/scheduler, encrypted private storage, resource limits, read-only roots, dropped capabilities, loopback-only gateway and health checks. Final staging services all healthy; anonymous login page 200, HTTPS/CSRF/authenticated dashboard and runtime workflow execution verified. No browser trust warning was bypassed and no OS trust root installed.

This is local staging, not production: private PostgreSQL TLS is disabled, mail uses an in-memory sink, providers are disabled and private service networking has no provider internet route. Production checks deliberately do not clear this profile. Full commands and closure requirements are in the [readiness checklist](operations/production-readiness-checklist.md).

## 13. Recovery and backup

Staging worker/scheduler were restarted, then later checks confirmed fresh issued-time heartbeats, zero queue backlog and zero failures. Go graceful-drain behavior has direct/race tests. Recovery commands retain bounded leases, dedup and ambiguous-provider-outcome handling; no blind real-message resend occurred.

The executed quiesced custom-format PostgreSQL backup restored into separate `flowmv_phase12_restore`, never over its source. All **83 table inventories/data checksums matched**; one synthetic account plus real workflow/request/audit records were retained. Private dump: 360,913 bytes, SHA-256 `0c569d3b8243da796fe593c300c8f2e9eb8560439a7ecbcb906ac88715cd6fc2`. Repetition refuses overwrite. Encrypted document-object/key restoration, off-site backups, PITR and production RTO/RPO remain unverified.

## 14. Live-provider matrix

| Provider | Implemented / simulated verification | Live response / acceptance / recipient evidence | Required next gate |
| --- | --- | --- | --- |
| Gmail | OAuth, mailbox/attachment pipeline and scoped send/reply regressions | Pending / pending / pending | Dedicated mailbox/app, exact callback, verified push and approved destinations |
| Outlook | OAuth/subscriptions, actual DOCX processing with simulated scanner, scoped outbox | Pending / pending / pending | Dedicated tenant/app/mailbox, exact callback/subscription state and public HTTPS |
| Telegram | Bot/identity/webhook/dedup/actions and interactive approvals | Pending / pending / pending | Dedicated bot, website token entry, public HTTPS webhook and approved chat/member identity |
| Slack | OAuth/HMAC/event/identity/channel and outbox | Pending / pending / pending | Dedicated app/workspace, exact callback/events URL and approved installed channel |
| Twilio SMS | Sender/recipient/consent/template/budget and signed delivery status | Pending / pending / pending | Dedicated account/sender, consented test recipient and explicit paid-send authorization |
| OpenAI GPT | BYOK/model/category/operation/budget/structured-advice tests | Pending / pending / not applicable as messaging delivery | Dedicated project key, approved data/model and explicit paid-inference authorization |
| Gemini | BYOK/Interactions/strict output/budget/failure tests | Pending / pending / not applicable as messaging delivery | Dedicated key/project, approved model/data and explicit paid-inference authorization |

Use the exact website Telegram steps and provider requirements in [connector setup](integrations/README.md), the preserved Phase 9B/9C/9D reports for email/Slack/SMS detail, and [AI setup](integrations/ai.md). Perform live tests only in a separate approved non-demo environment after TLS/egress/scanner/secret gates close. Provider acknowledgement is not independent recipient delivery. No exactly-once external-message promise.

## 15. Automated checks

| Check | Actual result |
| --- | --- |
| Full Laravel PHPUnit | 265 cases: 251 passed, 14 PostgreSQL-only skipped; 3213 assertions |
| Guarded PostgreSQL | 14 passed, 125 assertions on disposable local test DB; not QA/showcase/hosted data |
| Laravel Pint | Passed after changed PHP formatting |
| Go tests/vet/gofmt | 46 tests/seven packages passed; no formatting/vet findings |
| Linux Go race suite | Passed; native Windows race toolchain unavailable, Linux CGO check used |
| Go microbenchmarks | Executed with 500 ms bounds, CPUs one/four; provider-free |
| Vitest final | 126 passed/29 files, including 30 token contrast cases |
| TypeScript / ESLint | Passed; final pair rerun sequentially |
| Production frontend builds | Final native, Docker demo and slim staging builds passed (32 routes) |
| Staging guard tests | Four passed: wrong/hosted/owned targets, unowned volumes, secret separation and inventory failures |
| Staging operational smoke | Trusted HTTPS login/dashboard/CSRF/session/no-store/runtime publication/execution/logout passed; zero provider calls |
| Dependency checks | Composer zero; npm zero; govulncheck no reachable findings |
| Image CVE/SBOM | Pending: Docker Scout public-base scan required account login; no private upload or login performed |

Resource-heavy suites/builds were run as bounded separate checks. Three test mocks emit a React warning because they spread Next's boolean prefetch onto mock anchors; all assertions pass and application Next links do not do this. No unexecuted suite is counted as passed.

## 16. Browser acceptance evidence

Native Admin: ordinary login/logout, analytics scope/charts, connection provenance, protected revoke dialog cancelled without mutation, completed composed request/advice, desktop search/Tidy/Undo (no save), tablet panels and mobile read-only builder. Manager: ordinary login, task inbox, eligible Approve/Reject, absent Publish, light/dark presentation and logout. Employee: ordinary login, readable own Manager task without decision buttons, direct builder URL read-only, Telegram administration absent, personal policy-disabled connect, authorized requests at 320px and composed request visibility. No existing baseline task was decided or draft published during Phase 12 browser checks.

Sampled layouts: desktop 1280, tablet 768, mobile 390 and 320; both themes represented, not every page/role/viewport cross-product. Measured sampled document widths equal client widths (accounting for scrollbars). System theme and normal viewport restored. Private screenshot `backend/storage/app/phase12-audit/showcase-final.png` records the completed native request without credentials. Docker Admin login reached Overview and its independent composed request, showing completed status, version 1, original evidence, Manager approval and activity. Private screenshot: `showcase-docker-final.png`.

## 17. Documentation and diagrams

Root/index/current architecture, inventory, testing, setup, integration/security, performance, operations and presentation guides updated. Historical reports retain their original evidence. Three new diagrams describe prefetch privacy, candidate topology and recovery; **24 Mermaid diagrams rendered**, and all three new images were visually inspected. A clipped sequence note was shortened and rerendered. Local file-link validation passed with no missing targets; anchors and external URL availability are not covered. Final validation checked 147 local file links with zero missing targets. Generated artifacts remain ignored. Next feedback candidates were reviewed; no framework defect was retained (packaging/environment mistakes were application setup issues).

## 18. Remaining blockers and actionable closure

1. Provision dedicated provider accounts, exact OAuth/webhook URLs, destination/identity grants, content/privacy approval and paid-call authorization; capture live response separately from observed delivery.
2. Supply reviewed public/internal PKI, PostgreSQL verify-full TLS, independent secret-manager custody, non-owner runtime/migrator rollout and controlled fixed-provider egress. Do not copy demo credentials or weaken isolation.
3. Verify production ClamAV and isolated PDF processing, private-file retention, encrypted object/key restoration, off-site/PITR recovery and approved RTO/RPO.
4. Complete OS/container CVE/SBOM review, operational alert routing/retention, incident ownership, production-shaped load measurements and external security assessment.
5. Complete screen-reader, real zoom/motion and broader builder accessibility acceptance; close documented navigation debt.
6. Obtain explicit production release approval after critical gates close. Commands/runbooks are prepared; no automatic production action is authorized.

## 19. Production verdict

**NOT CLEARED.** The local release candidate and rehearsal improve deployment evidence, but missing live, infrastructure, recovery and independent-assessment gates prevent a justified production launch. Zero dependency advisories and passing regressions do not close these gates.

## 20. MINDCo showcase verdict

**Ready for the verified local simulated showcase**, with native and Docker baselines/AI/composed workflows preserved and ordinary authenticated role changes. Follow the [presentation script](demo/demo-script.md), keep simulation banners visible and describe scanner/provider/AI outputs accurately. Expired external approval buttons require a fresh guarded rehearsal or the authenticated task page; never rewrite timestamps or reactivate a consumed capability. This verdict is separate from live-provider and production readiness.
