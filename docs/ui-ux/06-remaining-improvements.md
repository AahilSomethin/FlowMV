# Remaining improvements

## Not built, on purpose

- **Configurable analytics layouts.** There is no saved layout on the server. A local-only drag-and-drop dashboard would look configurable and then forget the arrangement on another browser. Left out.
- **Period-over-period deltas.** `AnalyticsReport` is one UTC window. Showing a comparison would invent a number.
- **Replacing every native `<select>`.** Connection, template, and inspector forms still use native selects. They work with the keyboard and with the current tests. `.select-native` is available when those forms are next touched. Swapping them in one pass risked breaking option values and focus restoration.

## Not verified

- Signed-in Overview, Analytics, workflow studio, requests, tasks, team, and settings in a real browser. The API was not running.
- Screen reader behavior, 200% browser zoom, and reduced-motion emulation.
- A second full `vitest run` after the isolated telegram inspector retry. The first full run had one failure that did not reproduce alone.

## Worth doing later

- Close the focus jump on `#content` if it fights a control that should keep focus across a shallow query-string change.
- Give the workflow studio toolbar an overflow menu below `lg` if a real tablet session shows the actions wrapping into the canvas.
- Persist analytics layout only after the API can store it per user and organization.
