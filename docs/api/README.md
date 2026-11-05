# API contracts

API routes use `/api/v1`. The [generated route inventory](routes.md) records actual methods and controller actions; regenerate after route changes. Organization UUIDs resolve only through active membership, and resource UUIDs are additionally scoped to that organization. Never infer authorization from a successful UUID lookup in another endpoint.

## Browser authentication

Request `GET /sanctum/csrf-cookie` with credentials, then `POST /api/v1/auth/login` with `{email,password}`, the `X-XSRF-TOKEN` header decoded from the CSRF cookie and an allowed frontend origin. Subsequent requests include session cookies. `GET /api/v1/me` supplies organization state; logout uses the existing stateful route. Cookies, not bearer tokens copied into frontend storage, are the browser contract. Sensitive integration changes first call `POST /api/v1/integration-auth/confirm` with the signed-in account password. Confirmation is bound to that user and expires; 428 means repeat it through the UI.

## Workflows, requests and tasks

Workflow create accepts a name and description; draft save accepts graph/form definitions with the current revision. A stale revision returns conflict. Publishing requires Admin authorization and a valid graph with all referenced provider grants. Published versions and submission/decision snapshots are immutable. Requests accept fields defined by the pinned version. Task decision and completion endpoints recheck role/user eligibility, open state and current membership under locks. External workflows require authorized activation proof; posting a form cannot impersonate a webhook.

## Phase 10 authoring endpoints

All following paths are beneath `/api/v1/organizations/{organization}`:

| Method/path | Contract |
| --- | --- |
| `GET workflow-templates` | Catalog `{version,items,can_create}`; safe metadata only |
| `POST workflow-templates/{template}` | `{name,bindings}`; author policy; returns 201 ordinary workflow resource, draft revision 2, no published version |
| `POST workflows/{workflow}/preview` | Author policy; accepts optional graph_definition **and** form_definition together, or version_uuid, or saved draft by default; `preview_inputs` and `scenario` are synthetic only |
| `GET workflows/{workflow}/compare?from=UUID&to=UUID` | View policy; versions must belong to workflow; structural changes without config values, raw messages or credential references |
| `GET connections/overview` | Approved provider metadata and visible connection status/provenance, no secret payloads |
| `GET demo-status` | Membership required; banner notice only after server-owned demo guard; false outside demo |

Template bindings are exactly the recipe's required keys: none for internal forms; `connection_id,destination` for Telegram/Slack; `connection_id,document_template_id,email_sender` for email/document recipes. A missing setup returns 422/403/404, never an invalid placeholder draft. Catalog version is distinct from the unchanged workflow registry version.

Preview accepts a combined maximum 200,000-byte definition/input scenario; existing definition limits still apply. Scenario keys are validated by `WorkflowPreview`: synthetic event fields, attachment count/blocked state, document values and explicit hypothetical decisions. Response contains `mode`, `persisted:false`, `external_delivery:false`, fingerprint, result, trace, skipped node IDs, safe input summaries and findings. It does not return secret/configuration contents. A completed hypothetical trace is not a real workflow run. Provider adapters require current grants; unsupported operations return unavailable. Trace is bounded to 320 transitions and five seconds of evaluation.

## Connections and webhooks

Provider setup endpoints retain their existing contracts. Use [provider instructions](../integrations/README.md) and the route inventory. Sensitive actions are POSTs or policy PUTs with step-up; tokens are write-only. Verified webhook endpoints are public by necessity but check provider signatures/secrets, bounded payloads and duplicate-event IDs. They do not create organization access. Personal credentials are not automatically shared. Queued work rechecks current authorization.

## Errors and privacy

| HTTP | Meaning |
| --- | --- |
| 401 | Authentication required or expired |
| 403 | Policy, operation, grant or destination denied |
| 404 | Resource missing or outside visible tenant |
| 409 | Stale revision, completed task or incompatible connection state |
| 419 | Stateful CSRF failure |
| 422 | Invalid graph, field/configuration or request shape |
| 428 | Fresh password confirmation required |
| 429 | Rate limited; honor retry guidance |
| 503 | Fail-closed integration/configuration or demo guard unavailable |

Validation responses may carry field errors and graph violations with safe node/edge/field IDs. Provider errors use stable safe codes. Do not echo provider responses, credentials or private content into exceptions or support tickets. Read-only comparison and preview responses are `no-store, private`.

## Phase 11 advisory AI

See the [AI REST contract](ai.md) for organization policy, scoped BYOK connections, reference-only analysis and authorized result reads. Go is private and is never a browser API.
