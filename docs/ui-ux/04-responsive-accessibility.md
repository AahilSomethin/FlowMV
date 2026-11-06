# Responsive behavior and accessibility

## What was checked in the browser

The Next.js dev server was running. The Laravel API on port 8000 was not, so signed-in screens could not be opened. Checks below are for `/login`.

| Check | Result |
| --- | --- |
| 375px width, dark (system) | Form card, labels, and full-width Sign in button. `documentElement.scrollWidth` equaled `clientWidth` (375). No horizontal overflow. |
| 800px width, dark | Brand panel hidden. Centered card. |
| 1280px emulation, dark | Deep indigo brand panel with light text. The capture window was narrower than the emulated page, so the form column was only a sliver in the screenshot. |
| Light brand panel, first pass | Full-bleed `primary` became a pale field in dark mode. Replaced with the `brand` token and rechecked. |

Screenshots: `docs/ui-ux/screenshots/`.

## Layout rules in the code

- Page content uses a max width, not a fixed height.
- Dialogs that already capped height keep `max-h-[90dvh]` and scroll inside.
- Analytics charts use a bounded height (`h-64` / `h-72` on the volume chart, `h-44` on the donut) inside a card, not a viewport-tall canvas.
- The workflow studio still fills `h-dvh`. Below 768px it stays read-only. Between 768 and 1279px the library and inspector are sheets.
- Team and audit tables sit in `overflow-x-auto`. Request lists already switch to cards below `md`. Workflow performance rows are one list that stacks labels under `md`.

## Accessibility

- Date presets are radios in a named group.
- Status badges include visible text, not color alone.
- Skip link remains in the shell. Route changes close the mobile sidebar and move focus to `#content` when focus is outside the page.
- `prefers-reduced-motion: reduce` still collapses animation duration in `globals.css`.
- Contrast for the documented token pairs is enforced by `lib/theme-contrast.test.ts`, including brand text on the brand panel and status text on soft fills.

## Not verified

- A screen reader pass.
- Browser zoom at 200%. The device-metrics override is not the same as browser zoom.
- Light theme on a machine whose system preference is light. This session’s OS preference was dark, so the form screenshots are dark. The light token set is covered by the contrast test, not by a screenshot.
- Authenticated routes: Overview, Analytics, the workflow builder, requests, tasks, team, connections, and settings.
- Reduced-motion emulation in the browser.
