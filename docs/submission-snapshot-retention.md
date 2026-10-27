# Submission snapshot retention

Decision-time copies of workflow submissions are stored in `workflow_submission_snapshots`. Each row is the form data that existed when a request started, when an approver decided, or when a revision was submitted. The table is forward-only: rows are not created for decisions that happened before this table existed, and application code does not update or delete them.

## What is stored

- The snapshot contains the same submitted fields the parent request already shows to someone allowed to open that request.
- It does not add a new audience. Instance and task detail return an approval snapshot only after the existing request visibility check. Analytics, audit listings, and CSV exports do not select this table.
- Application code does not write snapshot payloads to logs.

## How long a row is kept

A snapshot is kept for the life of its workflow instance. Deleting the instance or its organization removes the snapshot through the foreign-key cascade. That cascade is a database delete, which the append-only rule allows. Updates are rejected by the model and, on PostgreSQL, by a trigger, so a snapshot cannot be edited in place.

There is no separate purge job in this release. Do not copy the current submission onto older decisions to fill gaps. If a future retention window is required, replace that design explicitly: delete or redact snapshot rows by instance age without rewriting `workflow_events` or completed task decisions.

## Production access

- Keep `APP_DEBUG` false so a database error page does not render bound query parameters.
- The runtime database role should not be granted `UPDATE` on `workflow_submission_snapshots`. `DELETE` is needed only so instance and organization cascades can remove rows. The application must not issue `DELETE` against the snapshot table itself.
- Restricted runtime credentials are still an open release item. Until `flowmv_app` from `docs/database-access.md` is in use, the elevated connection can update or delete rows directly. Treat that as unresolved.
