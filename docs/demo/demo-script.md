# MINDCo presentation script — 12 to 15 minutes

Prepare the [owned demo](demo-setup.md), verify integrity, keep the launcher and worker running, and privately open the generated account manifest. Use a desktop browser and ordinary authenticated role changes. This script uses synthetic content only; no provider console or real message is required. New guided interactions persist, so record any pristine-count drift rather than resetting data.

## 0:00–1:00 — frame the demonstration

Sign in as Admin. Show the fictional-workspace banner. Say: “Requests, decisions, document parsing and audit records run in Laravel. Email, Slack, Telegram, SMS and scanning responses are simulated. Live-provider verification is a separate gate.” Introduce Requests, My Tasks and Workflows in the navigation.

## 1:00–4:00 — scenario A: configure a process

Open Workflows → Explore templates. Search leave; explain required roles and draft-only creation. Create a separately named presentation draft. In the builder, inspect the date fields and Manager approval, use tidy/undo/redo, save and validate. Open hypothetical preview, fill synthetic answers and assume approved. Point out the fingerprint, chosen branch and no-side-effects notice. Close preview and verify no request appeared. Publish as Admin only after reviewing validation; explain version pinning. This adds a real new workflow and intentionally changes pristine totals.

## 4:00–8:00 — scenario B: email/document correction

Open Connections → Email and show the simulated mailbox, approved employee reply address and workflow grants. Use its local simulated event controls for the seeded employee sender and missing-information scenario; wait for the worker to fetch and parse. Sign out and sign in as Employee 1. Open the awaiting intake and **confirm while authenticated**. Show the resulting request timeline, missing Employee ID finding and correction task. Correct the synthetic fields through the revision task; do not edit database rows. Sign out and sign in as Manager 1, review the request/decision evidence and approve. Return to Email delivery history as an authorized Admin if needed; show the accepted simulated correction/outcome messages and explain acceptance versus real delivery. The actual DOCX parser ran, while scanning was simulated.

## 8:00–11:30 — scenario C: Slack to Telegram to outcomes

As Admin, open Slack's simulated event control. Use the seeded linked sender `U90000002`, command `/request`, approved channel and a fresh event key. Wait for one authorized request. Show its pinned version and Manager approval. Open Telegram's simulated approval controls and select the pending task/delivery; send the synthetic button decision before its ten-minute expiry. The normal task engine must record the Manager decision. Show Slack acceptance and SMS status in their histories; both remain simulated. Re-submit the same Slack event key and show that it does not create a second request. Explain that unsigned events, an unlinked identity, stale grants or a revoked connection fail closed. If the capability expired, use ordinary Manager approval in FlowMV and disclose that fallback.

## 11:30–14:00 — scenario D: evidence and role boundaries

Open analytics with a date range covering the fixture clock. Explain visible-request scope, UTC grouping and minimum cohort size five. Open audit/request history and one immutable decision snapshot; show pinned version and real chronological actions without private message bodies. Sign out and sign in as Employee 1: show that authoring, publishing, shared-provider administration and broad analytics are restricted. Return to Admin by normal logout/login. On a narrow viewport show readable tasks/requests and the builder's mobile read-only guidance.

## 14:00–15:00 — close and disclose gates

Show Connections evidence provenance and the structural published-version comparison. Explain that credentials are encrypted and UUID references alone do not authorize execution. State actual native/container verification results from the acceptance report. End with the independent live-provider, production scanner/PDF, least-privilege, retention and deployment gates. AI is implemented with simulated acceptance; live AI quality, billing and provider behavior remain unverified. Do not claim production readiness.

If a control or worker fails during rehearsal, stop that scenario, show its safe diagnostic and record the failure. Never manufacture successful history or silently replace provider evidence.

## Phase 11 addendum: advisory AI walkthrough

Use ordinary synthetic Admin login to open Connections → OpenAI & Gemini. Explain selected providers, read categories, workflow grants, processing-unit caps and simulated provenance. As Employee, inspect the seeded incomplete application, its revision snapshot and revalidated advice; generated drafts are unsent. As Manager, inspect the verified Slack request and original evidence, then the completed Telegram approval example with a single decision snapshot. Show uncertain and provider/budget fallback requests awaiting human review. Never present model advice as a decision or fake provider transport as live verification. Use the scenario references from `demo:ai-seed --verify` rather than hardcoded IDs. This addendum supplements the existing 10–15 minute MINDCo script.

For a single composed narrative, substitute the request returned by `demo:ai-seed --rehearse --verify` for scenario B. Show its original DOCX evidence, missing-field analysis, fixed correction message, authenticated Employee revision, revalidated fields and summary, linked Manager decision and two final recipient-specific email acceptances. The rehearsal is already completed; inspect its real timeline and snapshot rather than approving it again. Explain that all three provider email acceptances and AI responses are simulated, while Laravel processing and DOCX extraction actually ran. Use the remaining scenarios to demonstrate uncertainty and failure handling.

## Phase 12 engineering evidence

Within the opening and closing minutes, show the typed React frontend and intent navigation, then explain Laravel owns permissions/transactions, Go owns bounded signed advisory REST processing, and PostgreSQL protects published versions and decisions. Use the current performance report for the verified analytics asset reduction and one fewer SQL query; do not promise a latency gain unsupported by measurements. Show separate native and Docker launchers, developer guides, and the isolated candidate/backup evidence. End with the NOT CLEARED production verdict and actionable live/operational gates. This replaces an extended technical detour so the presentation remains 10–15 minutes.
