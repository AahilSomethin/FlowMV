# Reproducible MINDCo showcase

The demonstration is fictional. Laravel requests, tasks, decisions, revisions, snapshots and outboxes are real application records; provider traffic and scanner results are simulated. DOCX extraction uses the actual bounded parser. No provider token or public webhook is needed. Real provider and production scanner/PDF testing remain separate gates.

## Native profile

Install prerequisites and dependencies from the [root README](../../README.md). Run from root:

```powershell
node scripts/demo-setup.mjs native
node scripts/demo-run.mjs
```

Setup generates a fresh PostgreSQL cluster in ignored `backend/storage/app/phase10-demo/native/postgres`, bound to 127.0.0.1:55490, with its own `flowmv_demo_owner` and `flowmv_phase10_demo`. It generates independent database password, APP_KEY, vault key and installation token in ignored `backend/.env.demo`. It verifies actual server data-directory ownership before creating the database, installs the shared database comment marker only on an empty target, migrates forward and seeds through domain services. It cannot reuse phase QA or hosted credentials. `FLOWMV_PG_BIN` can point to the PostgreSQL tool directory.

Open `http://localhost:3010`; API is `http://localhost:8010`. Generated passwords live only in ignored `backend/storage/app/phase10-demo/native/accounts.json`. Use `mindco.admin1@example.com`, `mindco.manager1@example.com` and `mindco.employee1@example.com` with their respective generated passwords. Never publish the manifest. Login/logout normally to change roles; there is no authorization-bypass role switcher. The launcher uses a separate session cookie and Next dist directory. It refuses occupied ports and stops only its own children on Ctrl+C. PostgreSQL and data remain intact.

After a computer restart, repeat the guarded `node scripts/demo-setup.mjs native` command to start the existing owned PostgreSQL cluster and verify it without overwriting records, then start the launcher. The Windows pg_ctl subprocess uses detached output handles so a running server cannot hold the setup capture pipe open.

For a native production bundle, build frontend with `NEXT_PUBLIC_API_URL=http://localhost:8010`, `FLOWMV_SESSION_COOKIE=flowmv10-demo`, `FLOWMV_DIST_DIR=.next-phase12-native-production`, then stop the owned development launcher and run `node scripts/demo-run.mjs --production`. Separate output directories preserve the running development build. A successful dev server is not production-build acceptance.

From the root in PowerShell, with the development launcher stopped:

```powershell
$env:NEXT_PUBLIC_API_URL = 'http://localhost:8010'
$env:FLOWMV_SESSION_COOKIE = 'flowmv10-demo'
$env:FLOWMV_DIST_DIR = '.next-phase12-native-production'
npm run build --prefix frontend
node scripts/demo-run.mjs --production
```

## Container profile

Start Docker Desktop's Linux engine first. Then from root:

```powershell
node scripts/demo-container.mjs up
node scripts/demo-container.mjs verify
node scripts/demo-container.mjs stop
```

The wrapper generates a separate ignored `backend/.env.demo.container` and passes its database password without printing it. Compose has a private database/worker network and a separate ingress bridge for web/API, no exposed database port, owned named database/private-storage volumes and IPv4/IPv6 loopback-only web/API ports 3011/8011. Base images are pinned by verified digests. A pre-Laravel initializer verifies actual database/user/owner/private address and marks **only an empty database**, then migrates and seeds. The runtime guard requires the same installation token. Replacing the secret file while keeping an existing volume intentionally fails marker/password checks; preserve the installation files with their volume.

Open `http://localhost:3011`. Read generated accounts locally with `docker compose -f compose.demo.yaml exec -T api cat storage/app/phase10-demo/accounts.json` only in your own terminal; that file contains passwords and must not enter screenshots/log reports. Do not use `docker compose config` in public output because env resolution can expose secrets. There is no reset/down-volume wrapper. Docker setup must be independently executed before it is called accepted; consult the [actual ledger](../phase10-acceptance.md).

## Dataset and integrity

One organization has 24 users: two Admins, six Managers and sixteen Employees. Five department labels are manifest metadata, not permissions. Eight published demonstrations cover leave, reimbursement, procurement, IT access, onboarding, multistage approval, email/document correction and Slack → Telegram approval → Slack/SMS outcome. The controlled fixture clock creates records in chronological order over about 58 days through actual actions; no audit timestamps are rewritten. Each request pins a real version and each completed approval has one decision snapshot.

The pristine distribution is 73 completed, 10 rejected, nine awaiting approval, six awaiting assignment and two awaiting correction. Analytics uses the same database and preserves cohort suppression. Email cases include genuine extraction, revision submission and outcome outboxes. The cross-channel cases use verified synthetic ingress and expiring Telegram capabilities; old pending buttons expire normally.

From backend run `php artisan demo:verify --env=demo`. Repeating `demo:seed` verifies existing records without overwriting them. A failed new seed transaction rolls back database records; encrypted unreferenced parser/cache artifacts can remain private and are not evidence of a completed dataset. No automatic destructive cleanup is supplied. Integrity scopes baseline records to their manifest IDs and checks analytics against current workspace totals. Additional guided requests/workflows are retained. Changing an original fixture can legitimately report baseline drift; never force it back by editing audit/snapshot data.

Historical fixture processing invokes real domain/outbox services synchronously under the fixture clock, with job dispatch captured to prevent delayed real-clock processing. The running demo subsequently uses normal database workers and scheduler. Provider-specific phase QA guards remain unchanged; the general demo guard is an additional boundary.

## Phase 11 extension

The launchers now include the pinned private Go service. Re-running guarded setup appends only missing AI settings and preserves existing application/vault/database keys. Start the native launcher before `php artisan demo:ai-seed --env=demo --no-interaction`; use `docker exec flowmv-phase10-demo-api-1 php artisan demo:ai-seed --no-interaction` for the container. `--verify` performs no seed writes. Ten scenario ledger entries may share requests and leave deliberate human-review tasks open. Original 100 historical requests and manifest remain intact. Never run the extension against real AI credentials. See [current acceptance](../phase11-ai-go-acceptance.md).

After the ten scenarios, run the composed rehearsal once per owned installation:

```powershell
# From backend, with the native launcher running
php artisan demo:ai-seed --rehearse --env=demo --no-interaction
php artisan demo:ai-seed --rehearse --verify --env=demo --no-interaction
# From root, with Compose running
docker exec flowmv-phase10-demo-api-1 php artisan demo:ai-seed --rehearse --no-interaction
docker exec flowmv-phase10-demo-api-1 php artisan demo:ai-seed --rehearse --verify --no-interaction
```

The separate `mindco-ai-leave-v1` ledger creates one composed request through domain services: verified synthetic email and real DOCX extraction, missing-field advice, fixed correction email, authenticated Employee revision, field revalidation and summary, linked Telegram Manager approval, then fixed employee and HR outcome emails. It verifies three AI runs, three simulated accepted email actions and one decision snapshot. Repeating the command verifies the existing request without overwriting it. Neither provider acceptance nor the simulated scanner constitutes live delivery or production malware protection.
