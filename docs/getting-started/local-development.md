# Native development

Superseded by the consolidated [Quick start](../quick-start.md).

For specialist environment variable tables and troubleshooting retained below for tooling compatibility:

Verified host versions for the showcase baseline were PHP 8.4.23, Laravel 13.38, Sanctum 4.3, Node 22.19, Next 16.4.3, React 19.3 and PostgreSQL 18. Dependency lockfiles are authoritative.

Use the [guarded showcase](../showcase-guide.md) for a reproducible local database. Existing `.env.phase85`, `.env.phase9b`, `.env.phase9c` and `.env.phase9d` files belong to prior private QA; do not reuse, print or overwrite them.

## Configuration map

| Variables | Purpose / handling |
| --- | --- |
| `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL` | Runtime identity, application encryption and URLs; debug must be false for demos/deployment |
| `DB_*` | PostgreSQL connection. Demo refuses DB_URL and exact-target mismatches |
| `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_*` | Stateful browser session and CSRF |
| `NEXT_PUBLIC_API_URL`, `FLOWMV_SESSION_COOKIE`, `FLOWMV_DIST_DIR` | Browser API URL and Next build directory |
| `INTEGRATION_ACTIVE_KEY`, `INTEGRATION_KEYS` | Independent vault keys |
| Provider `INTEGRATION_*` / `SLACK_*` / `SMS_*` | See `backend/config` |

## Troubleshooting

See [Quick start](../quick-start.md#troubleshooting).
