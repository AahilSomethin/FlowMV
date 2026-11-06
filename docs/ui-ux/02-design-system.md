# FlowMV design system

The interface uses Tailwind CSS v4 and the existing shadcn `radix-nova` primitives. Tokens live in `frontend/app/globals.css`. Components must use semantic colors (`bg-card`, `text-muted-foreground`, `bg-primary`) rather than raw palette utilities.

## Color

Ink and indigo. Surfaces are cool and quiet. Indigo is reserved for the primary action, the active navigation state, and the default chart series. Status colors carry meaning.

| Token | Role |
| --- | --- |
| `background` | App canvas |
| `card` / `popover` | Raised surface and menus |
| `surface-subtle` | Inset bars, filter trays, table headers |
| `primary` / `primary-soft` | Main action and a quiet tint behind it |
| `destructive` / `destructive-foreground` | Solid destructive actions |
| `status-success`, `status-warning`, `status-info`, `status-neutral` | Text and marks |
| `status-*-soft`, `status-danger-soft` | Badge and banner fills |
| `chart-1` … `chart-6` | Categorical chart series, independent of workflow node colors |
| `node-*` | Workflow step accents only |

Light and dark values are opaque OKLCH except borders and shadows. `frontend/lib/theme-contrast.test.ts` checks the text pairs used on page, card, primary, destructive, and status-soft surfaces.

System theme still applies through the `prefers-color-scheme` block, because choosing System does not add the `.dark` class. That block matches `.dark`.

## Type

Geist is the sans face. Geist Mono stays available for identifiers.

| Use | Classes |
| --- | --- |
| Page title | `text-2xl md:text-3xl font-semibold tracking-tight` |
| Section title | `text-base font-medium` |
| Body | `text-sm leading-6` |
| Meta | `text-xs text-muted-foreground` |
| Eyebrow | `text-xs font-medium tracking-wide uppercase text-primary` |
| Figures | `tabular-nums` |

## Spacing and layout

- Page stack: `gap-6` between header, filters, and sections.
- Cards use the shadcn card spacing. Do not add a second padding system inside `CardContent` unless the content is a full-bleed chart.
- Workspace content is `max-w-6xl`. Analytics, requests, team, and connections use `max-w-7xl`.
- The workflow studio is the exception: it fills the viewport and does not use the page measure.

## Surfaces and controls

- Cards: `bg-card` with the existing ring. `shadow-(--shadow-card)` on stat cards.
- Buttons: default height 36px, large 40px. `destructive` is a solid fill. `destructive-outline` is the quieter danger action used before a confirm dialog.
- Inputs and selects default to 36px.
- Native `<select>` elements that cannot move to the Radix select yet can use the `.select-native` class.
- Badges: `StatusBadge` maps tone to a soft fill plus a dot. Tones are `neutral`, `attention`, `success`, `info`, and `danger`.
- Toasts: Sonner, mounted once in the root layout.
- Date presets and theme choices use `ToggleGroup` when the control is a single choice and the accessible role can be a radio.

## Charts

Charts read `--chart-*` and status tokens. Tooltips use the popover surface. Empty cohorts render `EmptyState` and no chart. Rates that fail `sufficient` keep the string `Not enough completed requests`. There is no period-over-period series because the API does not return one.

## Workflow canvas

React Flow colors are the same CSS variables as the app. Each node type sets `--node-accent`. Nodes use an accent rail, a 36px icon chip, and outcome labels at `text-xs`. The control shadow uses `--shadow-card`.

## Motion

Hover and focus use the existing color transitions. Spinners use `animate-spin`. `prefers-reduced-motion: reduce` collapses animation and transition duration in `globals.css`.
