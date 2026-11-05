# Integrations and connector development

The vault and authorization foundation precede provider execution. Telegram, Gmail/Outlook email, Slack and Twilio SMS have provider-specific services, bounded operations, ingress verification and durable outboxes. Simulated acceptance maturity is recorded in the historical [Telegram](../archive/phase9b-telegram-acceptance.md), [email and documents](../archive/phase9c-email-document-acceptance.md) and [communications](../archive/phase9d-communication-acceptance.md) reports. Primary guide: [Workflows & integrations](../workflows-and-integrations.md). Showcase traffic is simulated. No live-provider claim follows from a successful local test.

## Credential lifecycle

Use Connections while authenticated in the intended workspace. Create personal credentials only if organization policy allows; shared credentials require an Admin. Confirm your password for sensitive changes. Tokens are encrypted immediately, never displayed again. Admins approve the provider, connection and authorized destinations; explicit per-workflow operation grants are required before publication. Testing confirms only the specific recorded operation. Disabling pauses/cancels eligible work; revocation removes secrets/grants and pauses intake. Rotation increments credential generation where implemented and may require reapproval. Old queued actions must fail closed when generations or permissions change.

| Provider | Credential and destination boundary | Incoming trust |
| --- | --- | --- |
| Telegram | Bot token; approved chat/group, workflow operation; interactive approval only linked private identity | Webhook secret, update deduplication, linked identity; decision capabilities expire and are single-use |
| Gmail/Outlook | OAuth authorization code with state/session binding and PKCE; read/send capabilities; approved reply addresses | Gmail push verification or Outlook subscription clientState; recognized sender **and authenticated FlowMV confirmation** |
| Slack | OAuth bot install, approved workspace/channel and workflow operation | Timestamp/HMAC, replay deduplication, confirmed member identity |
| SMS | Twilio account credentials; Admin-owned sender, consented recipient, approved minimal template, country/budget limits | Provider callback signature plus delivery/account binding; no employee actions from SMS |

## Connecting a real Telegram bot through the website

This requires a separate non-demo environment, dedicated test bot and public HTTPS origin. Never put a production token in the demo, fixtures, chat or source.

1. Configure the backend's Telegram enable flag, **disable simulation**, set `INTEGRATION_TELEGRAM_WEBHOOK_ORIGIN` to the public HTTPS API origin and set a real HTTPS frontend URL. Install the vault key through secret management. Confirm queue worker and scheduler operation.
2. Sign in as an Admin, open Connections → Telegram and approve Telegram for the organization. Confirm the password when prompted.
3. Select personal or shared ownership as permitted, name the connection and enter the bot token in the website's protected field. Connect calls Telegram `getMe` to validate bot identity. This proves token control, not unrelated account ownership.
4. Approve the connection, configure the webhook, and run Test. Verify the returned webhook health through FlowMV. Message the bot or add it to the intended group using Telegram; select/approve the exact destination and required bot membership.
5. For interactive decisions, the Manager starts identity linking in FlowMV, sends the shown challenge privately to the bot and confirms the received identity in FlowMV. A group message cannot impersonate a Manager.
6. Create a draft, bind the approved connection/chat, grant only its required operations, validate, then publish as Admin. Publication creates version-pinned subscriptions only for external-trigger nodes.
7. Send a dedicated synthetic live event. Confirm one request, correct pinned version, authorized decision, outbox attempt and safe audit events. Verify the actual received notification independently in Telegram. Record provider response separately from observed recipient delivery, including timestamp and test destination.
8. Exercise duplicate event, disable/revoke and stale-grant checks in the dedicated test setup. Leave live verification pending until this independent evidence exists.

Gmail/Outlook require provider-console OAuth registration, exact redirect URLs and push/subscription configuration. Slack requires exact redirect, app/scopes, signed command/event URLs and installed channel membership. Twilio requires an owned SMS sender, consent evidence, allowed country and account budget. Their provider approvals, app review, billing/consent and deliverability remain separate live gates. The demo does not contact their consoles.

## Documents

`DocumentPipeline` encrypts private evidence and controls access/expiry. DOCX extraction validates archive names, entry counts, expansion bounds and XML safety. The showcase runs this real parser on synthetic DOCX files. Its scanner returns deterministic simulated results. Production ClamAV and digest-pinned PDF isolation are separate fail-closed requirements, not demonstrated antivirus/sandbox assurance. An uncertain extraction never becomes invented field values. Only authorized request participants may access evidence; diagnostics omit document contents.

## Adding a connector

Use the `Connector` interface and neighboring provider services as the contract: credential requirements, available triggers/actions, explicit configuration schema, validation, webhook verification, rate/retry policy and safe audit metadata. Restrict hosts and endpoints in provider-owned transport code. Never add an unrestricted HTTP node, arbitrary base URL or arbitrary code execution. Keep OAuth callbacks single-use and session/organization bound. Separate owner policy, provider approval, workflow grant and destination authorization; recheck all at job execution. Add deduplication, bounded leases/timeouts, ambiguous-delivery handling and recovery tests before UI activation. Use fake transports first, then independent real-provider verification. Do not log secrets or message bodies. Do not silently upgrade published contracts.

## Advisory AI

[OpenAI/Gemini setup, ownership, data policy and corrections](ai.md) describes the optional advisory integration. Live-provider verification remains pending.
