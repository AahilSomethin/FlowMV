# OpenAI and Gemini connections

AI advice is optional and never an approval. The browser calls Laravel; Laravel authorizes and minimizes the source, reserves usage and sends a signed request to private Go. Go uses only the configured OpenAI or Gemini adapter. Read the [architecture](../architecture/go-service.md), [API contracts](../api/ai.md) and current [acceptance ledger](../phase11-ai-go-acceptance.md).

## Website setup

1. On an appropriately configured **non-demo** installation, the operator enables `AI_ENABLED`, starts the private Go service and provisions its independent shared `AI_SERVICE_KEY`. Production requires private authenticated TLS. Keep `AI_SIMULATED=false` for both Laravel and Go. Never put real keys in the showcase or fixtures.
2. Sign in as an organization Admin. Open **Connections → OpenAI & Gemini** (`/connections/ai`). Choose **Organization AI policy**, confirm your FlowMV password, approve providers and the exact information categories allowed to leave FlowMV, set input/output limits, per-organization/user/workflow daily units, correction limits and retention. Provider billing limits remain separate.
3. Choose **Connect AI provider**. Select the provider, ownership, permitted model and operations, smaller connection limits and a descriptive name. Enter your own provider key in the password field and confirm your FlowMV password. FlowMV encrypts it with the existing separately managed vault key and never displays it afterward.
4. Use **Test**. It fetches only the selected model's metadata, without workplace content or an inference request. A successful metadata test does not verify structured-output processing, factual quality, billing or live delivery. Keys restricted to inference endpoints may need explicit metadata-read permission at the provider.
5. An Admin chooses **Approve use**. For a personal connection, its owner first chooses **Request approval**: this explicitly exposes only its review metadata to Admins. Admins cannot read, rotate or use another person's key. Once approved, it disappears from other Admins' private-connection review list.
6. The owner/Admin chooses **Allow a workflow** and grants each exact operation. A connection is not automatically usable by all organization members. Personal grants remain owner-only at execution time. Configure source fields, information categories, structured schema and outcomes in the workflow builder, then publish a new immutable version.
7. Exercise a synthetic, nonconfidential request with an authorized test account. Review advice and original evidence inside FlowMV. Record actual provider evidence separately from simulated evidence, verify reported usage, failure handling and revoked grants, and only then approve broader use.

Allowed models are explicitly maintained by the operator in `backend/config/ai.php`; the UI offers that allowlist. Provider availability changes, so verify account/model access independently. No other AI provider, arbitrary base URL, model tool or custom code endpoint is available.

## Ownership and permission matrix

| Operation | Personal owner | Another member/Manager | Organization Admin |
| --- | --- | --- | --- |
| Create personal key | Only if organization permits personal connections and AI provider/data policy | May create own under same policy | May create own under same policy |
| Create organization key | No, unless Admin | No | Yes |
| Read stored plaintext key | Never | Never | Never |
| Test/rename/disable/rotate/revoke | Own connection only, with step-up | No | Organization connection or own personal connection only |
| Request private-connection approval | Owner explicitly opts into metadata review | No | For own personal connection |
| Approve connection use | Only if owner is an Admin | No | Organization key; another personal key only after owner request |
| Grant workflow operation | Owner for own private key | No management of another key | Organization key or own private key |
| Execute analysis | Owner, with active policy, exact grant, current membership and authorized source | Organization key only with exact approved workflow operation and authorized source | Same runtime checks; Admin status does not bypass another person's ownership |
| Review generated advice | Current request visibility, source authorization and retention | Current request visibility; credential ownership is not transferred | Same runtime checks |

Sensitive policy/credential/grant actions require a fresh step-up session and existing CSRF/session/rate protections. Rotation/configuration clears approval and cancels pending processing. Disable/revoke/ungrant remain available when AI policy is off. Revocation deletes FlowMV's stored key and grants; revoke the key at the provider separately.

## What information is sent

Request analysis reads only the explicitly selected non-file form fields. Email reads the authorized, retained canonical plain-text subject/body already stored by email intake; upstream content bounds still apply. Slack reads only a verified linked event with current workflow/channel permissions. Document analysis reads authorized, unexpired, clean extracted text. It never sends a file to the AI provider or bypasses malware scanning, ZIP/XML limits or PDF sandbox requirements. Normal request file-field submission retains the existing engine limitation; email evidence is the demonstrated document path. Scanned PDFs and unsupported extraction remain unavailable, not implicitly AI-enabled. A null document field can select the sole email evidence attachment; ambiguous multiple attachments require explicit selection/human review.

Common email addresses, international phone patterns and recognizable token patterns are redacted before Go. This is not comprehensive sensitive-data detection: names, HR facts and financial details may remain. Organization approval and author-declared data categories are necessary privacy controls, not a guarantee of anonymization. Oversized content is rejected rather than silently truncated by AI processing. Original immutable evidence and decision snapshots remain unchanged.

Advice is encrypted with context-bound vault encryption, retained for the policy period (1–30 days), and reauthorized before viewing. Requested model, prompt version, provenance and provider-reported token counts are stored separately from encrypted text. The resolved model is unknown unless explicitly captured; it is not fabricated. Keys, source text and advice are absent from queued job payloads, workflow JSON, exports and audit payloads. Authorized advice responses contain the advice by design; do not log response bodies.

## Builder and corrections

V4 introduces AI Email Analysis, AI Document Analysis, AI Slack Analysis, AI Summarization, AI Classification, AI Field Extraction and AI Reply Draft. V1/V2/V3 published types and snapshots remain unchanged. Configuration includes connection reference, source, selected fields, a structured schema, fixed category/route IDs, explicit data categories and optional mappings that **display suggestions** for form fields. Mappings never overwrite submitted evidence.

Connect `complete`, `missing`, `uncertain` and `unavailable` outcomes. Complete means the configured schema passed validation, not that a request is approved. Missing/uncertain may enter the existing bounded Revision path; an authenticated employee corrects the form, Laravel snapshots the revision, and a pinned AI node can revalidate it. Correction analyses are capped by organization policy and revisions by the published node. Unavailable/budget failures should lead to human review. Existing approval nodes enforce current reviewer eligibility, no-self-approval and one decision.

Editor preview stops at unsupported AI evaluation without calling Go, decrypting keys, dispatching jobs or persisting domain records. Guided demo execution is separate: it creates real domain records on the guarded demo database and uses deterministic providers. Reply drafts remain unsent; this phase provides no model-directed sending, recipient selection or automatic forwarding. Existing explicitly approved email templates may send fixed correction instructions, and Telegram approvals link back to authenticated FlowMV evidence without forwarding confidential AI/source bodies.

## Usage and recovery

A serialized policy lock protects per-UTC-day organization, actor, workflow and connection reservations. Reserved units conservatively include input bytes, configuration overhead and requested output allowance; they are **not money or a guarantee of exact provider token billing**. Failed/ambiguous calls retain their reservation. Providers may charge for reasoning or rejected work; use provider account spend controls and reconcile usage independently.

`integrations:recover-ai` runs every minute. It purges expired advice ciphertext, fails processing leases older than two minutes without resending ambiguous inference, redispatches pending safe references and resumes pinned outcomes. There is no automatic paid retry or provider fallback. Cancellation/revocation is checked again after any response; unauthorized results are discarded. Vault key rotation now covers AI advice as well as credential secrets. Keep old keys until retained data and backups no longer require them.
