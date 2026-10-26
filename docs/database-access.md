# Database access

FlowMV uses Supabase as a PostgreSQL host. Laravel owns the schema through migrations. Do not point the application at the Supabase `service_role` key, and do not enable Supabase Auth for this app.

The current local connection authenticates as an elevated admin role (`postgres` or `supabase_admin`). That is broader than the application needs. This document describes the replacement roles. Do not apply it automatically, and do not reset or rewrite existing tables. Phase 1A does not add a migration, so the migrator role is only needed for later schema changes.

Create these roles in the Supabase SQL editor when you are ready, then store the passwords outside git.

## Runtime role

`flowmv_app` is the account Laravel uses while serving requests.

```sql
create role flowmv_app login password 'replace-with-a-secret';

grant usage on schema public to flowmv_app;
grant select, insert, update, delete on all tables in schema public to flowmv_app;
grant usage, select on all sequences in schema public to flowmv_app;

alter default privileges in schema public
    grant select, insert, update, delete on tables to flowmv_app;
alter default privileges in schema public
    grant usage, select on sequences to flowmv_app;
```

Set `DB_USERNAME=flowmv_app` and `DB_PASSWORD` to that secret in the untracked backend `.env`.

## Migration role

`flowmv_migrator` is a separate account for `php artisan migrate`. It is not the runtime account.

```sql
create role flowmv_migrator login password 'replace-with-a-different-secret';

grant usage, create on schema public to flowmv_migrator;
grant all privileges on all tables in schema public to flowmv_migrator;
grant all privileges on all sequences in schema public to flowmv_migrator;
```

Use it only for a migration command, for example by passing `DB_USERNAME` for that process. Keep `flowmv_app` in the normal application environment.

Until those roles exist, the elevated connection still works and Phase 1A does not refuse to boot because of it. Switch roles before real user data is stored.
