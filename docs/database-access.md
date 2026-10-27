# Database access and credential transition

Laravel is the authorization authority. Supabase hosts PostgreSQL; Supabase Auth and service-role API keys are not used by FlowMV. Do not apply this procedure automatically to a hosted database.

## Roles

Use a dedicated database/schema for FlowMV. The runtime role must be NOSUPERUSER, NOCREATEDB, NOCREATEROLE, NOINHERIT, NOBYPASSRLS, own no application objects, and have no membership in the migrator or administrative roles. It needs CONNECT on the application database, USAGE on the application schema, and the exact grants in `backend/database/runtime-permissions.sql`. Substitute the provisioned runtime identifier with psql's identifier substitution, for example `psql ... -v runtime_role=flowmv_app -f backend/database/runtime-permissions.sql`. Use the object owner to apply grants. This script contains no credentials and does not provision roles.

Mutable application tables receive SELECT/INSERT/UPDATE/DELETE. Published versions, events, and submission snapshots receive SELECT/INSERT only. Runtime does not need migrations-table access, schema CREATE, TRUNCATE, sequence ownership, or sequence UPDATE. USAGE on sequences permits generated IDs. Future tables receive no automatic runtime grants: each migration must explicitly review and apply its required grants before deployment.

The migration role owns FlowMV tables, sequences, indexes, and trigger functions and has schema USAGE/CREATE. Granting ALL on tables does not confer ownership and is insufficient to alter existing tables. The migration role is used only by the deployment migration process; web, queue, and scheduler processes use runtime credentials.

Provision the two login roles using the platform's approved secret workflow. Set the above role attributes explicitly. Generate distinct passwords outside source control. Do not paste passwords into SQL files, shell history, reports, or application logs. Password creation and hosted role changes remain a manual production operation.

## Ownership and schema review

Before migration-role transition, inventory the existing application objects. Transfer only the following FlowMV tables with `ALTER TABLE public.<table> OWNER TO flowmv_migrator`:

`migrations`, `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `organizations`, `organization_memberships`, `organization_invitations`, `workflows`, `workflow_drafts`, `workflow_versions`, `workflow_instances`, `workflow_tasks`, `workflow_events`, `workflow_revision_counters`, `idempotency_records`, `notification_outbox`, `workflow_delays`, `notifications`, `workflow_submission_snapshots`.

Transfer each owned serial/identity sequence with `ALTER SEQUENCE public.<sequence> OWNER TO flowmv_migrator`; obtain exact names from `pg_get_serial_sequence('public.<table>', 'id')` for the listed tables that have generated numeric IDs. Transfer the two FlowMV functions with `ALTER FUNCTION public.workflow_events_reject_mutation() OWNER TO flowmv_migrator` and `ALTER FUNCTION public.workflow_submission_snapshots_reject_update() OWNER TO flowmv_migrator`. Preserve their triggers. Never use a blanket REASSIGN OWNED against a shared Supabase administrative role.

On a dedicated schema, revoke CREATE from PUBLIC and grant it only to the migrator. Check inherited/PUBLIC privileges as well as explicit grants; revoking a role's own CREATE grant cannot override CREATE inherited from PUBLIC. On a shared Supabase schema, obtain a reviewed isolation plan before changing PUBLIC or schema ownership. FlowMV trigger functions should not be callable by runtime or PUBLIC; triggers execute without a caller EXECUTE grant. Review function defaults for newly created functions rather than exposing SECURITY DEFINER functions to runtime. No such new functions are added by Phase 8.5.

## Manual production switch

1. Take and verify a recoverable backup. Inventory object ownership, RLS, role memberships, PUBLIC privileges, connection pooling, TLS, and the exact application database/schema. Do not reset the schema.
2. Provision the restricted runtime and separate migration logins through the approved secret manager. Transfer only the reviewed FlowMV objects above. Apply the checked-in grant script as their owner. Reapply grants after future migrations; do not default-grant every future table.
3. In a staging copy, run migrations with the migration secret, then test registration/verification/login, organizations/invitations, publishing, submission/revision/approval, analytics, sessions, queue/outbox and delay processing with runtime credentials. Run the permission denial tests against a disposable copy.
4. Inject runtime credentials into web, worker, and scheduler environments. If DB_URL is used, replace that full secret too: it can override individual DB_* values. Keep TLS certificate validation enabled for hosted PostgreSQL. Use the migration secret only for the migration process.
5. Rebuild Laravel configuration through the normal deployment command, restart web/worker/scheduler processes, and verify effective current_user and database without logging credentials. Re-run the acceptance journeys. Retain the previous secret in the secret manager for rollback; do not retain it in the web environment.
6. Revoke the elevated application's old login only after observation and approval. This phase does not perform that hosted transition.

## Local verification and limits

The PostgreSQL acceptance suite creates a temporary NOLOGIN runtime role only in the guarded disposable loopback cluster. It executes a real start/approval under the exact checked-in grants, checks denied immutable writes and DDL, and checks snapshot foreign-key cascade without direct child DELETE permission. The temporary role and grants are removed afterward. Results are recorded in `docs/phase85-acceptance.md`.

PostgreSQL foreign-key cascades do not require a runtime DELETE grant on the child snapshot table. Existing `workflow_events` triggers nevertheless reject DELETE including cascades, so deleting a request/organization with audit events is currently blocked. Do not disable that trigger as an incidental retention workaround. Runtime credentials in the hosted deployment have not been changed or verified; this remains a release gate.
