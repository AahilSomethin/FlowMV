# Native development and prerequisites

The verified host versions at the start of Phase 10 were PHP 8.4.23, Laravel 13.38, Sanctum 4.3, Node 22.19, Next 16.4.3, React 19.3 and PostgreSQL 18. Dependency lockfiles are authoritative. PHP needs PDO PostgreSQL, ZIP, mbstring, XMLReader, DOM and OpenSSL; `php -m`, `composer check-platform-reqs`, `node --version` and `pg_config --version` help diagnose setup.

Use the [guarded showcase](../demo/demo-setup.md) for a reproducible local database. It generates its own keys and credentials. Existing `.env.phase85`, `.env.phase9b`, `.env.phase9c` and `.env.phase9d` files belong to prior private QA; do not reuse, print or overwrite them.

For ordinary development, copy `backend/.env.example` into a private local environment and configure a separate PostgreSQL database, application key, frontend URL and Sanctum origins. Run `composer install` in backend and `npm ci` in frontend. Apply reviewed forward migrations with `php artisan migrate`. Run the API, queue worker, scheduler and frontend as separate processes. The demo launchers are preferable when evaluating synthetic integrations: a client-side flag alone never enables a fake transport.

## Configuration map

| Variables | Purpose / handling |
| --- | --- |
| `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL` | Runtime identity, application encryption and URLs; debug must be false for demos/deployment |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_URL`, `DB_SSLMODE` | PostgreSQL connection. Demo refuses DB_URL and exact-target mismatches |
| `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DRIVER`, `SESSION_COOKIE`, `SESSION_LIFETIME` | Stateful browser session and CSRF origin configuration |
| `NEXT_PUBLIC_API_URL`, `FLOWMV_SESSION_COOKIE`, `FLOWMV_DIST_DIR` | Browser API URL, proxy cookie name and separate Next build directory. NEXT_PUBLIC values are public and baked at build time |
| `CACHE_STORE`, `CACHE_PREFIX`, `QUEUE_CONNECTION`, `DB_QUEUE_RETRY_AFTER`, `MAIL_MAILER` | Durable processing and scoped caches. Demo uses database queues/cache and array mail |
| `INTEGRATION_ACTIVE_KEY`, `INTEGRATION_KEYS` | Versioned independent vault keys; secret JSON map of key ID to base64 32-byte key |
| `INTEGRATION_TELEGRAM_*`, `INTEGRATION_EMAIL_*`, `GOOGLE_MAIL_*`, `MICROSOFT_MAIL_*`, `SLACK_*`, `SMS_*` | Provider configuration; consult actual config files. Demo requires all simulated flags and synthetic OAuth clients |
| `INTEGRATION_DOCUMENTS_ROOT`, `DOCUMENT_*` | Encrypted private evidence, extraction bounds, retention and scanner/PDF isolation configuration |
| `FLOWMV_DEMO_PROFILE`, `FLOWMV_DEMO_INSTALLATION_TOKEN`, `FLOWMV_DEMO_PRIVATE_ROOT`, `FLOWMV_PG_BIN` | Owned showcase identity, private storage and optional PostgreSQL binary directory override |

Configuration files under `backend/config` are the complete source for defaults and bounds. Never put tokens or vault keys into NEXT_PUBLIC variables. The demo's fake OAuth client strings and SMS fixtures are deliberately synthetic constants.

## Troubleshooting

- Login 419/CSRF: use the documented hostname consistently, verify Sanctum frontend host **and port**, cookie name and CSRF cookie request. Do not disable CSRF.
- 428 on provider changes: confirm the signed-in account password through the step-up prompt; confirmation expires after five minutes.
- Demo ownership refused: compare exact profile, server/database owner and installation marker. Do not change the marker to adopt a nonempty database.
- Port in use: launchers refuse to terminate another process. Resolve its ownership before stopping it.
- New frontend changes absent: check the dedicated dist directory and environment used at build/start.
- Job pending: confirm worker and scheduler are running, examine safe status/error codes and revalidate grants. An uncertain delivery needs duplicate-risk review before retry.
- PDF/scanner unavailable: fail closed; the showcase demonstrates real DOCX parsing with a simulated scanner and makes no production antivirus claim.
