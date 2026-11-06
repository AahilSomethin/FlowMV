# Verification report

Date: 2026-11-06. This pass opened the authenticated app. The earlier note that the API was down is obsolete.

No workflow serialization files were edited (`frontend/lib/workflows/definition.ts`, `editor-state.ts`, `types.ts`, `visible-graph.ts`). Drafts still save `{ revision, graph_definition, form_definition }`.

## Local stack

- API: `php -S 127.0.0.1:8000` from `backend/public`, using the native demo environment. `APP_URL`, `FRONTEND_URL`, and `SANCTUM_STATEFUL_DOMAINS` were overridden in that process only. `backend/.env.demo` was not rewritten.
- Queue worker: `php artisan queue:work --env=demo`.
- UI: Next.js dev on `127.0.0.1:3000` with `NEXT_PUBLIC_API_URL=http://localhost:8000` and `FLOWMV_SESSION_COOKIE=flowmv10-demo`.
- Sign-in has to use `http://localhost:3000`. A page opened on `127.0.0.1:3000` does not receive the session cookie set by `localhost:8000`.
- Accounts were the existing demo users in `backend/storage/app/phase10-demo/native/accounts.json`. No production credentials were changed.

The dev server was stopped only so `npm run build` could replace `.next`, then started again on port 3000.

## Authenticated visual review

The Cursor browser pane stayed at `devicePixelRatio` 0.8 and ignored viewport emulation, so each route was loaded in a same-origin iframe at 375, 768, 1024, and 1440 CSS pixels. `scrollWidth` was compared with `clientWidth`. Screenshots of those framed pages were inspected in dark and light (`localStorage` key `flowmv-theme`).

Screens opened while signed in: Overview, Analytics (charts, decisions, workflow performance), Workflows, Workflow studio, Requests, My Tasks, task detail, request detail, new request, Team, Connections, Settings, and workflow templates.

| Width | Page overflow after fixes |
| --- | --- |
| 375 | None on Overview, Analytics, Workflows, Requests, Tasks, Team, Connections, Settings |
| 768 | None after the Analytics workflow grid moved from `md` to `lg`. Before that, Analytics `scrollWidth` was 804 against a 749 client width |
| 1024 | None on the same routes. Requests and Workflows tables no longer scroll sideways |
| 1440 | None on Overview, Analytics, Workflows, Requests, Tasks, Team, Connections, Settings, and the studio |

Screenshots in `docs/ui-ux/screenshots/`:

- `overview-1024-dark-fixed.png`, `overview-1024-light.png`, `overview-375-dark-fixed.png`
- `analytics-1024-dark-charts.png`, `analytics-1024-light-charts.png`, `analytics-1024-dark-table-fixed.png`
- `builder-1440-dark.png`, `builder-new-draft-dark.png` (the new draft was captured in light)
- `workflows-1024-dark-fixed.png`, `requests-1024-dark-fixed.png`
- `tasks-1024-dark.png`, `team-1024-dark.png`, `connections-1024-dark.png`, `settings-1024-light.png`

### Fixes from this pass

- Overview task rows kept their status badge inside the card. Recent request titles wrap, with the status under the name, instead of truncating to a few characters.
- The 30-day approval-wait card no longer says the value is hidden when a median is shown.
- Quick actions stack in one column below `sm`, so labels are not clipped at 375.
- Analytics workflow rows show an em dash when the sample is too small. The full phrase “Not enough completed requests” stays available to assistive tech. The eight-column grid starts at `lg`, so 768 no longer overflows.
- Workflow names and descriptions wrap, and status and version stay on screen at 1024.
- Requests hide Version and Submitted by until `xl` and let the workflow name wrap, so the table no longer needs a horizontal scrollbar at 1024.
- The Settings theme control sizes to its buttons.

## End-to-end demonstration

Done in the signed-in UI, using the running queue worker.

1. Manager 01 created **Interview acceptance leave** from the Employee leave request template. The studio showed the start, manager approval, and both outcomes. Check workflow reported “This draft passes validation.”
2. Admin 01 published it. The studio reported “Published version 1.”
3. Employee 01 submitted it (details plus 16–18 Nov 2026). The request opened as awaiting approval.
4. Manager 01 approved the Manager review task. The request then showed Completed, with the approval recorded on the current submission.
5. Analytics for 2026-10-08 to 2026-11-06 UTC showed 49 requests started (48 before this request). The Interview acceptance leave row showed 1 started and 1 completed. The completion rate stays hidden because one finished request is below the minimum sample, which is the existing rule, not a missing number.

The local demo database now contains that workflow and completed request.

## Telegram inspector

`components/workflows/telegram-approval-inspector.test.tsx` failed once in an earlier full run: the private-chat option never appeared and the selects only had placeholders, which is the component’s empty catch path. The inspector source was not changed.

This session ran the full frontend suite three times after that:

| Run | Result |
| --- | --- |
| Full suite before the last visual edits | 140 passed, including the inspector test (163ms) |
| Full suite again, same tree | 140 passed, including the inspector test (217ms) |
| Full suite after the visual fixes | 140 passed |

The failure did not come back. An isolated pass was not treated as enough. The repeated full-suite passes, with no inspector or API-client change, point to an intermittent test-load race rather than a product regression.

## Commands after the visual fixes

| Command | Result |
| --- | --- |
| `npx vitest run` in `frontend/` | 140 passed. Exit 0. |
| `npx vitest run lib/theme-contrast.test.ts components/analytics/analytics-ui.test.tsx components/workflows/telegram-approval-inspector.test.tsx` | 53 passed across 3 files. Exit 0. |
| `npx eslint components app lib --max-warnings 0` | Exit 0. |
| `npx tsc --noEmit` | Exit 0. |
| `npm run build` | Exit 0. Next.js 16.4.3 compiled successfully and emitted `/dashboard`, `/analytics`, and `/workflows/[workflowId]/edit`. |

## Not verified

- A screen reader pass.
- 200% browser zoom and OS text scaling. Widths above were CSS viewports, not zoomed browser chrome.
- Reduced-motion playback.
- Every dialog at all four widths. Publish confirmation and request-submission confirmation were used during the demo. The approval confirmation was used on the task page.
- The Next.js dev overlay reported a hydration mismatch at `app-shell.tsx` while the browser tool was injecting `data-cursor-ref`. The signed-in screens still rendered and the production build succeeded. That overlay was not treated as an app layout bug.
