# Organization-scoped AI REST API

All browser routes are under `/api/v1/organizations/{organization}/integrations/ai`. They retain stateful Sanctum authentication, session expiry, active organization membership, CSRF and integration request-body redaction. They are not public Go endpoints. Normal responses use the existing `data` envelope. Keys never appear in responses.

| Method / suffix | Behavior |
| --- | --- |
| GET `/` | Safe policy/model/operation catalog, visible connection metadata, workflow grants and actor's recent processing metadata |
| PUT `/policy` | Admin + step-up; approve providers, information categories and bounded usage/retention/corrections; cancel pending work |
| POST `/connections` | Step-up; create owned credential and safe connection settings |
| POST `/connections/{connection}/{action}` | Step-up; test, rename, configure, rotate, approve, request-approval, enable, disable, grant, ungrant or revoke |
| POST `/analyze` | Reference-only analysis request; returns run ID and queued status; additional 10/minute limit |
| GET `/runs/{run}` | Current-authorized, unexpired advice or safe status; rechecks original source and permissions |
| GET `/requests/{instance}` | Request-visible recent advice; unavailable/expired results expose safe status only |

The integration route group is limited to 30 requests/minute; step-up confirmation has its separate existing five/minute limit. Go has an additional bounded global concurrency/rate limit.

## Creation and settings

Credential input is `{ "data": { ...settings }, "credentials": { "api_key": "<entered only through the website>" } }`. Settings: `name` (1–100 chars), `provider` (`openai` or `gemini`), `ownership` (`personal` or `organization`), allowlisted `model`, allowed `operations`, `max_input_bytes` (512–100000), `max_output_tokens` (256–4096), `daily_units` (1000–10000000). A key is encrypted before storage. Policy/grant/configuration requests use the `data` object; rotation uses `credentials`.

Policy fields: `enabled`, provider list, explicit `data_categories`, input/output limits, `daily_organization_units`, `daily_user_units`, `daily_workflow_units`, `max_corrections` (0–3), `retention_days` (1–30). Grant data is `workflow_id` (public UUID) and exact operation. Rename data is `name`. No arbitrary provider endpoint, prompt, model tool or recipient is accepted.

## Analysis request

```json
{
  "data": {
    "workflow_id": "<authorized workflow UUID>",
    "connection_id": "<approved connection UUID>",
    "operation": "summarize",
    "source_kind": "request",
    "source_id": "<visible request UUID>",
    "configuration": {
      "input_fields": ["details"],
      "fields": [],
      "categories": [],
      "routes": [],
      "data_categories": ["workplace_request"]
    }
  }
}
```

Other source kinds are email intake UUID, private document UUID and completed Slack activation UUID. Each is resolved within the organization and workflow and reauthorized when queued and after the response. Email/document/Slack analysis operations require their matching source kind. There is no raw-text public analysis endpoint.

`fields` uses the existing bounded document schema, including required values, types, select options, date relationships and conditional requirements. Empty arrays are explicit. Categories/routes are finite IDs, not instructions. The internal prompt version is pinned to `workplace-v1`. Request deduplication includes organization, actor, workflow, connection, operation, source fingerprint, configuration and policy/credential generations. It is not a promise of exactly-once paid inference across ambiguous failures.

Advice returns validated summary, classification, structured fields, missing/inconsistent IDs, optional route suggestion, unsent draft and human-review recommendation. Laravel adds deterministic validation and `complete`/`missing`/`uncertain` outcome. Metadata distinguishes `simulated`, `provider_response` and `unknown`; requested model is separate from an unknown resolved model. Source content, stored keys and raw provider errors are excluded.

## Failure contracts

401/session expiry and 419/CSRF follow existing authentication behavior. 403 denies permissions/provider/data policy; 404 conceals cross-tenant/private resources; 409 rejects revoked/changed configuration or bounded correction conflicts; 422 rejects schema/source/limits; 428 requires step-up; 429 indicates request/service/budget limits; 503 indicates safe service/provider unavailability. Provider text, credentials and raw exception details are never returned. Workflow processing can route an unavailable result to human review while preserving original evidence and current authorization.

See [internal signed contract](../architecture/go-service.md) and [website setup](../integrations/ai.md).
