# Product UX and security findings register

Static review preserves established PageHeader, loading, error, empty-state, navigation, theme and role patterns. The original register below describes Phase 10; the Phase 12 update follows it. Browser results are recorded per journey; this register does not imply accessibility certification or unchecked views passed.

| Area | Finding / preserved behavior | Phase 10 action and verification |
| --- | --- | --- |
| Authentication | Stateful Sanctum/CSRF and normal role changes must remain authoritative | Native Admin/Manager/Employee normal login/logout and Docker Admin login passed. Expired session redirected to login after services resumed; retryable outage state was explicit |
| Workspaces | Workspace labels are not authorization; switching must re-resolve current membership | Existing transition/remount behavior preserved; no demo role switcher added |
| Requests and timeline | Pinned versions and immutable evidence must remain visible without private messages | Existing timeline/snapshot protections retained; seeded chronology and analytics verified |
| Tasks | Role eligibility, no-self-approval and concurrent completion must remain server enforced | Seed actor-role checks and PostgreSQL concurrency suite passed; fresh Employee correction and Manager approval completed through browser tasks |
| Workflows | Existing search/status filters and published/draft distinction are useful | Template entry added; version comparison excludes private values and credential references |
| Builder | Search, tidy, undo/redo and unsaved changes already implemented | Retained; native preview/check workflow and tidy/undo exercised; 390px read-only mode verified |
| Builder mobile | Long title truncated to a few characters | 390px production screenshot confirms full heading, read-only explanation and footer; no document overflow in the sampled view |
| Configuration | Provider permissions and preview assumptions must be explicit | Binding wizard, setup links, exact destination checks and safe hypothetical trace implemented |
| Connections | Provider cards fragmented; success lacked common provenance | Shared cards distinguish simulated/provider response/unknown history; no delivery inferred |
| Connections mock area | Disabled local mock form looked like global provider status | Hide unused disabled controls; clarify this connector is separate from provider cards |
| Analytics | Cohort suppression and visible-request scope are security requirements | Preserved; baseline integrity agrees through OrganizationAnalytics, and Admin 90-day browser totals show the 102 requests after rehearsal. Manager/Employee scope explanations were verified |
| Team | Admin membership controls must not become a demo bypass | Employee view-only explanation and absent role/invite controls verified; ordinary authenticated role changes used throughout |
| Settings | Browser theme preferences and account/workspace detail already exist | Both themes exercised across all three roles and desktop/tablet/mobile cases; System preference restored afterward |
| Docs/onboarding | Framework boilerplate and missing central entry obscure maturity | Root/index/current guides added; historical paths preserved |

The [final acceptance report](phase10-acceptance.md) records the representative role/theme/viewport matrix, wizard Escape/focus restoration, native required-field focus, safe preview field alerts, labels and status semantics. Screen-reader, measured contrast, effective 200% zoom and reduced-motion emulation remain unverified. Browser zoom shortcuts had no effect and no motion-emulation capability was exposed. No accessibility certification or exhaustive cross-browser claim is made. Simulated provider operations remained labeled as such. No new broken journey was found in the four rehearsed scenarios; shared-host latency and browser-control timeouts were resolved by inspecting settled states.

## Phase 12 verification update

The [current report](phase12-final-acceptance.md) records final counts and production gates. Auth now has a primary heading/status semantics, refresh-generation protection and safe CSRF recovery. Intent navigation prepares bounded route shells only. Analytics defers charts with a stable loading/failure boundary; its initial JavaScript is measured, not an inferred navigation speed claim. Selected light/dark text and form/control token contrast is tested; destructive controls use opaque surfaces.

Native Admin checked analytics, provenance, a cancelled password-protected revoke dialog and preserved composed request/advice. Dialog reverse-tab stayed trapped and Escape restored Revoke focus. Desktop builder search/Tidy/Undo returned to Draft saved without a write; tablet panels and 390px read-only mode passed. Manager saw eligible Approve/Reject and no Publish. Employee saw no Manager-task decision or shared Telegram administration controls, policy-disabled personal connection setup, and a read-only direct builder URL. 320px request list and 390px Connections/request views had no sampled document overflow. Docker normal Admin login and its independent completed composed request passed.

System theme and normal viewport were restored. Native/Docker baseline and AI integrity checks passed without overwriting fixtures. Actual screen-reader, browser 200% zoom, motion emulation, exhaustive contrast and third-party React Flow nonvisual editing remain unverified. The mobile sidebar can remain open after route navigation; Escape dismisses it, but route-change focus restoration needs further work. No broad WCAG claim follows from selected checks.
