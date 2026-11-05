# Frontend loading and prefetch

## Baseline and scope

Installed Next.js 16.4.3 and React 19.3.0 use Cache Components and partial prefetching. The installed version's bundled documentation was reviewed before edits. Existing automatic Link behavior warms each visible route shell. Private business data is fetched by authenticated client effects, not embedded in a reusable server cache.

The prior native production bundle is the before artifact. `node scripts/profile-navigation.mjs before` measures authenticated HTML requests and initial script bytes across login, overview, tasks, requests/detail, workflows/builder, connections, analytics/audit, team and settings. The after command must run against the rebuilt bundle. These measurements do not represent browser hydration or full client transitions. The current read-only browser surface exposes no Performance API; that measurement limitation must remain explicit. A modeled 120 ms RTT / 256 KiB/s case is an estimate, not browser network emulation.

## Implemented policy

`IntentPrefetchProvider` prepares one role-specific next shell after 700 ms on Overview: Employee Requests, Manager Tasks, Admin Workflows. Analytics prepares Audit History. `IntentLink` disables automatic viewport-wide speculation, prepares after a 120 ms hover or keyboard focus, and cancels timers on leave, blur, unmount or scope change. Started Next prefetches cannot be aborted with the supported API. Next owns deduplication/reuse of route payloads.

Speculation stops offline, with Save-Data, or on reported slow-2g/2g/3g. The metadata-only budget allows at most eight unique authorized route shapes per minute and is partitioned by current user, organization and role. Paths with query strings, OAuth callbacks, document routes and unknown providers are rejected. There is no automatic download of record collections, documents, provider secrets, audit exports or AI advice. Detail shells are warmed only after deliberate link intent.

## Data and invalidation boundary

No client query library or private data cache was added: this phase has no evidence that their complexity is justified. Every API fetch uses no-store, and the API returns private/no-store. Normal server policies reauthorize every request and execution. CSRF bootstrap sharing stores only an in-flight promise; existing cookies are reused, 419 forces renewal for eligible retries, and unkeyed POST remains unreplayed.

Logout unmounts the private shell; organization switches hide/remount content; refreshed user/organization/role changes remount views. No children mount before authentication completes. Each entered page still fetches its current authorized data. Route shells contain no private business records, so reuse cannot authorize stale actions. Approval completion and provider delivery remain server-confirmed. Budget metadata is replaced on a scope change; it never stores API responses or credentials.

## Verification and tradeoffs

Pure budget/network/route tests and component focus/hover/cancellation tests cover the policy. Unused speculative shells still consume bytes if the user changes intent after dispatch. Poor-link signals intentionally trade warm shells for fewer wasted downloads; unavailable Network Information signals fall back to the bounded budget.

## Measured bundle comparison

The initial intent-navigation comparison used twelve routes, one process-cold HTML request and three warm HTML requests each, concurrency one. These are HTTP/document measurements, not hydrated client navigation timings. Private JSON artifacts preserve both runs under the ignored local performance audit directory.

| Initial scripts | Before raw / estimated gzip bytes | After raw / estimated gzip bytes |
| --- | --- | --- |
| Analytics | 1,313,224 / 403,216 | 949,706 / 299,299 |
| Builder | 1,281,171 / 398,754 | 1,283,461 / 399,564 |
| Overview | 788,205 / 251,849 | 790,535 / 252,664 |

Analytics defers Recharts until authorized report data is available. Its initial raw JavaScript fell about 27.7%, estimated gzip about 25.8%. The chart still downloads when needed; this is deferral, not elimination. A chart error boundary preserves the rest of the report if loading fails. Builder/common shell overhead increased slightly; no builder speedup is claimed. Charts use stable plot heights and disable animation.

The serial network model for Analytics fell from 3876.66 to 3479.05 ms at 120 ms RTT and 256 KiB/s. That roughly 398 ms difference is a model, not measured browser latency. Warm Analytics HTML median was 12.49 versus 13.09 ms: no server latency improvement. Three warm requests do not justify a p95. Actual hydration CPU, client transition timing and throttled-browser network measurements remain unavailable through the current browser inspection surface. No p99 is reported.

The final verification command is `node scripts/profile-navigation.mjs after-final`, against the native production build. Build with `NEXT_PUBLIC_API_URL=http://localhost:8010`, `FLOWMV_SESSION_COOKIE=flowmv10-demo` and the same `FLOWMV_DIST_DIR` used by the launcher; public environment values are compiled into the bundle and cannot be corrected by `next start` alone.

Session generation checks prevent an old unauthorized response from expiring a newer login and prevent a completed refresh from restoring a logged-out user. A 419 renews CSRF even for an unreplayed POST; only an eligible idempotent request is automatically retried. A sibling local profile changing the shared XSRF cookie is covered explicitly. These checks do not cache authorization or replace server enforcement.

## Final twelve-route HTTP samples

Times are milliseconds; one process-cold request and three warm requests per route. Shared-host variation and server warm state make these descriptive samples, not statistically established navigation speedups. Initial script counts exclude dynamic imports, API requests and browser prefetch traffic.

| Route | Cold before / after | Warm median before / after | Initial scripts before / after | Raw JS before / after bytes |
| --- | --- | --- | --- | --- |
| `/login` | 84.62 / 718.74 | 66.09 / 85.8 | 13 / 13 | 845652 / 846302 |
| `/dashboard` | 19.48 / 27.75 | 16.35 / 13.76 | 15 / 15 | 788205 / 790535 |
| `/tasks` | 9.93 / 67.91 | 7.12 / 14.51 | 19 / 19 | 1005761 / 1008081 |
| `/requests` | 10.46 / 65.87 | 11.91 / 13.81 | 17 / 17 | 931921 / 934233 |
| `/requests/:id` | 19.54 / 86.76 | 10.63 / 13.62 | 19 / 19 | 999785 / 1002105 |
| `/workflows` | 8.98 / 13.38 | 8.83 / 16.54 | 18 / 18 | 970530 / 972844 |
| `/workflows/:id/edit` | 344.66 / 308.06 | 56.31 / 75.05 | 20 / 20 | 1281171 / 1283461 |
| `/connections` | 8.37 / 13.84 | 13.69 / 9.34 | 15 / 15 | 805238 / 807568 |
| `/analytics` | 7.96 / 12.59 | 12.49 / 13.09 | 18 / 18 | 1313224 / 949706 |
| `/analytics/audit` | 112.18 / 11.76 | 9.08 / 11.48 | 17 / 17 | 938900 / 941222 |
| `/team` | 19.53 / 70.9 | 8.88 / 11.11 | 18 / 18 | 974408 / 976744 |
| `/settings` | 11.11 / 12.66 | 7.82 / 13.64 | 15 / 15 | 786713 / 789055 |
