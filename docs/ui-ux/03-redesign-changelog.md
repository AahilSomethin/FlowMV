# Redesign changelog

Presentation changes only. API payloads, workflow graph serialization, permissions, and metric definitions are unchanged.

## System

- Refined the OKLCH palette in `frontend/app/globals.css`: ink text, cool surfaces, indigo primary, and a deep `brand` color that stays dark in both themes.
- Added `surface-subtle`, status-soft fills, `chart-1`–`chart-6`, `destructive-foreground`, and shadow tokens.
- Default buttons, inputs, and selects are 36px. Solid `destructive` and `destructive-outline` variants replace the old text-only danger button.
- Sonner toasts mount once in the root layout. Popover, toggle group, kbd, and spinner primitives were added. The nested tooltip provider in the app shell was removed.
- `PageHeader` accepts an eyebrow and meta. Shared pieces: `StatCard`, `SectionCard`, and `FilterBar`. Lists that tests address by link name use one stacking structure, not a second mobile copy.
- `StatusBadge` uses a soft fill and a dot. Completed requests read as success. In-progress requests read as info.

## Shell

- Sidebar groups: Work, Build, Insights, Organization.
- Data-heavy routes use `max-w-7xl`. The mobile sidebar closes on route change, and focus moves to `#content` when it is not already inside the page.

## Overview and Analytics

- Overview (`/dashboard`) loads open tasks, recent requests, and a 30-day pulse from the existing tasks, instances, and analytics endpoints. It links to Analytics instead of repeating the report.
- Analytics is a reporting surface: six KPI cards, a volume area chart, a status donut with counts and share, an approval-decision bar, workflow rows, and bottleneck rows. Insufficient samples still say “Not enough completed requests” and mention `minimum_sample`. Truncation notes point at CSV export. There are no invented period deltas.
- Audit uses the same filter tray, export toast, and card-wrapped table.

## Workflow builder

- Studio header groups the name, draft state, and actions. Node cards use an accent rail and `text-xs` labels. An empty canvas explains how to add a step. Edge stroke and the control shadow use tokens.
- `lib/workflows/definition.ts`, `editor-state.ts`, `types.ts`, and `visible-graph.ts` were not edited.

## Other screens

- Requests, tasks, workflows, team, connections, and settings use the shared header and, where it helped, the filter tray.
- Reject on a task is `destructive-outline`. Removing a team member is the same, with the confirm dialog still destructive.
- Team tables scroll inside the card on narrow screens.
- Sign-in and registration use a split layout from the `lg` breakpoint: deep brand panel, form card. Below that, the form stands alone.

## Tests adjusted

- The analytics date preset is a radio inside a toggle group. The test clicks that radio.
- The Recharts mock includes area and bar charts.

## Orqestra rebrand and inbox

- User-facing product name is Orqestra. The sidebar and sign-in panel use the tagline “Operational workflows, made clear.” The mark is an O of nodes and a routed path, including `frontend/app/icon.svg`.
- Internal identifiers stay: session cookie, `flowmv:*` keys, drag-and-drop type, and `X-FlowMV-*` headers.
- The full-width demo strip is a compact Demo control. It names email, Slack, Telegram, SMS, document scanning, and AI provider transport as simulated. The API notice string is unchanged.
- Showcase display names are aligned by `php artisan demo:align-labels`. It updates only the `mindco-showcase` organization name and connection names. Slugs, users, workflows, requests, and audit rows are not rewritten. Document templates stay immutable, so an existing “MINDCo application” template name remains.
- My Tasks is a wide inbox: real open and completed totals, search, status tabs, and sort of the loaded page. Approve stays primary. Reject stays a controlled destructive action. There is no new revision decision.
- Shell content padding is tighter. Data routes, including tasks, workflows, dashboard, and settings, use `max-w-7xl`. New request stays a narrower form. Empty states can be centered.
