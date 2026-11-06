# FlowMV design audit

Static review of the Next.js frontend before the visual overhaul. Severity is how much the issue hurts a first impression or a real task. Routes are App Router paths.

## Shell and system

| Severity | Finding | Routes | Cause | Change |
| --- | --- | --- | --- | --- |
| High | Overview is a welcome page. It does not show tasks, requests, or workspace health. | `/dashboard` | `DashboardScreen` only reads the session and renders destination cards. | Rebuild around open tasks, recent requests, and a short pulse that links to Analytics. |
| High | Analytics reads as a stacked report, not a decision surface. Charts use a fixed height, default tooltips, and a legend detached from the status chart. | `/analytics` | `h-64` chart cards and unstyled Recharts tooltips. | KPI row, token-colored charts, attached legends, responsive chart height. |
| Medium | Returned analytics fields never appear: `minimum_sample`, `share`, `truncated`, `oldest_open_assigned_at`. | `/analytics` | UI was written against a smaller subset of `AnalyticsReport`. | Surface them without inventing comparisons. |
| Medium | No period comparison exists in the API. | `/analytics` | `AnalyticsReport` has a single window. | Do not draw deltas. |
| Medium | Sidebar is one flat list. Active state and grouping do not match how people move through work. | All workspace routes | `WORKSPACE_NAV` has no groups. | Group Work, Build, Insights, and Organization. |
| Medium | Content is capped at `max-w-6xl` even on data-heavy pages. | Analytics, requests, team | One shell width. | Wider measure for tables and charts. |
| Low | Mobile sidebar can stay open after navigation. | All | Close only happens on the link click. | Close on pathname change and move focus to content. |
| Low | Dark theme variables are copied in `.dark` and a `prefers-color-scheme` block. | Global | System theme does not add the `.dark` class. | Keep both in sync; document the split. |
| Low | Button default height is 32px, so screens override `h-10`. Destructive buttons are outlined text on a card. | Global | shadcn `radix-nova` density. | Default 36px, solid destructive, outline destructive variant. |
| Low | No toast primitive. Success of export, invite, and save is easy to miss. | Global | Sonner is not installed. | Add Sonner and use it for completed actions. |

## Workflow builder

| Severity | Finding | Routes | Cause | Change |
| --- | --- | --- | --- | --- |
| High | The studio toolbar wraps a long row of equal buttons. Draft state competes with Publish. | `/workflows/[id]/edit` | All actions sit in one flex wrap. | Group history, review, and save. Collapse secondary actions on smaller widths. |
| Medium | Node type labels use 10px type. Outcome labels are easy to miss. | Builder canvas | Arbitrary text sizes on `WorkflowNode`. | Use the type scale, an accent rail, and clearer handles. |
| Medium | An empty graph has no guidance. | Builder | Canvas renders React Flow with no overlay. | Empty-canvas prompt that uses the existing add-node action. |
| Low | React Flow control shadow is a raw hex (`#00000008`). | Builder | One-off CSS. | Token shadow. |
| Low | Some node types rely on suffix selectors for their accent. | Builder | Accent rules cover the core types plus a few suffixes. | Cover AI, integration, and document types explicitly. |
| Constraint | Graph shape, undo, validation, and publish payloads are correct and must not move. | `lib/workflows` | Serialization lives outside the view. | Restyle only. |

## Requests, tasks, and the rest

| Severity | Finding | Routes | Cause | Change |
| --- | --- | --- | --- | --- |
| Medium | Request and task screens are functionally clear but visually flat: similar cards, weak status hierarchy, primary and destructive actions close in weight. | `/requests`, `/tasks` | Shared patterns used without a status hero. | Status summary, clearer action weight, same data. |
| Medium | Team tables scroll sideways on a phone with no stacked layout. | `/team` | `Table` only. | Stacked rows under `md`. |
| Low | Connections loading and errors are plain paragraphs. Several forms use unstyled native selects. | `/connections` | Screen predates the shared states. | Shared loading, error, and select styling. |
| Low | Create-organization uses a one-off header. | `/onboarding` | Built before `PageHeader`. | Align the header. |
| Low | Settings theme control is a row of buttons with no grouped treatment. | `/settings` | Ad hoc button group. | Toggle group, same three preferences. |

## What already works

- Semantic OKLCH tokens and a contrast test for the core pairs.
- Shared `PageHeader`, `EmptyState`, `ErrorState`, `LoadingState`, and `StatusBadge`.
- Request lists already switch to cards below `md`.
- The builder already has undo, tidy, unsaved-change guards, and a read-only mobile canvas.
- Analytics already explains scope, hides small samples, and refuses to chart an empty cohort.
- Tests pin accessible names, a few exact strings, and `data-testid="volume-chart"`. Those stay.

## Not in this repository

There is no `src/tour` guided tour. Navigation and accessible names are the stability contract instead.

Screenshots of the previous UI were not captured in this pass when the local app was not already serving a signed-in session. The verification report records what was actually opened.
