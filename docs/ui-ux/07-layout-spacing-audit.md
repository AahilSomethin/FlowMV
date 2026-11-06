# Layout and spacing audit

Reviewed in the signed-in native demo at `http://localhost:3010` on 6 Nov 2026. Light and dark were both opened. Widths were 375, 768, 1024, and 1440. Horizontal overflow was measured as `scrollWidth - innerWidth`. A result of about -15px is subpixel rounding, not a scrollbar.

Screenshots: `docs/ui-ux/screenshots/tasks-1440-light-selected.png`, `tasks-1440-dark-unselected.png`, `tasks-375-dark.png`, `overview-1440-dark.png`.

| Route or component | Identified problem | Severity | Root cause | Proposed solution | Implemented change | Verification result |
| --- | --- | --- | --- | --- | --- | --- |
| App shell | Data pages sat in `max-w-6xl` with large outer padding, so desktop felt empty | High | Content wrapper width and padding | Widen operational routes and reduce stacked padding | Wide routes use `max-w-7xl` and `p-4 md:p-6 lg:px-8 lg:py-6`. `/requests/new` stays `max-w-3xl` | Overview, tasks, requests, workflows, analytics, connections, team, and settings had no horizontal overflow at 1440 |
| Sidebar brand | Product name and tagline were FlowMV, and the tagline clipped | Medium | Single-line truncation in a fixed-height brand button | Wrap the tagline and use the new mark | Orqestra wordmark, wrapped tagline, `BrandMark` | Tagline wraps to two lines. Favicon is `frontend/app/icon.svg` |
| Demo notice | Full-width sentence dominated the shell | High | `DemoNotice` rendered the API notice as a strip | Compact control that still names simulated integrations | Header Demo button and screen-reader text list email, Slack, Telegram, SMS, document scanning, and AI transport | Present on every signed-in page. API notice string unchanged |
| My Tasks | Sparse cards, weak selected state, blank detail pane, unused desktop width | High | Narrow shell, card rows, left-aligned empty pane | Master-detail inbox with honest counts | Inbox plus sticky detail. Counts come from existing task totals. Due soon and completed today are omitted | Unselected and selected states checked at 1440 light and dark. 375 stacks without overflow. Detail pane appears at 1024. Approve stayed inside the pane |
| Overview | Same width and padding issue as the shell | Medium | Shared content wrapper | Use the wide shell | Covered by the shell change | 1440 dark. Four pulse metrics and the task/request columns render. No overflow |
| Requests | Filters and table already usable; width was the limit | Low | Shell max width | Keep the table and widen the page | Shell only | 1440, no overflow, no MINDCo or FlowMV copy |
| Workflows | Filters sat outside the shared tray | Low | Custom flex row | Use `FilterBar` and center the empty state | Filter tray and centered empty state | 15 rows at 1440, no overflow |
| Analytics | Width, not chart structure | Low | Shell max width | Widen with the other data pages | Shell only | 1440 light, no overflow |
| Connections, including email | Renamed connections. One template still says MINDCo | Medium | `document_templates` rejects updates | Rename mutable connection labels only | `demo:align-labels` updated 8 connection names. Template left in place | Email page shows Demo mailboxes and still shows “MINDCo application · 4 fields” |
| Team | Table density was acceptable once the page was wide | Low | Shell width | No table rewrite | Shell only | 24 member rows, no overflow |
| Settings | A second card listed only unavailable options | Low | Placeholder section | Keep the facts in the appearance card | Password and notification limits are one row | Page loads as Settings. Light theme control works |
| Sign-in | Product name was FlowMV | Medium | Auth shell copy | Orqestra mark and tagline | `AuthShell` | Title “Orqestra” and the tagline confirmed before sign-in |
| OAuth callbacks, invitation accept, verify email, onboarding, register | Not opened | — | They need a provider code, invitation token, or an account with no workspace | Do not invent those states | No change | Not verified |
| Workflow studio | Not opened in this pass | — | Needs a selected draft and a full canvas session | Leave the existing full-height canvas | No builder logic change | Not verified in the browser |

## Counts that were not invented

Needs action and Completed use `listTasks` totals. Revisions in view counts `task_type === "revision"` on the loaded page and hides at zero. There is no due date on a task, so Due soon is not shown. Completed today is not shown because the task API has no day filter.
