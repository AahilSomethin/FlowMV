-- Run as the object owner, substituting a separately provisioned runtime role.
-- This file does not create credentials, transfer ownership, or change PUBLIC.
GRANT USAGE ON SCHEMA public TO :runtime_role;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE
    users, password_reset_tokens, sessions, cache, cache_locks,
    jobs, job_batches, failed_jobs, organizations, organization_memberships,
    organization_invitations, workflows, workflow_drafts, workflow_instances,
    workflow_tasks, workflow_revision_counters, idempotency_records,
    notification_outbox, workflow_delays, notifications
TO :runtime_role;
GRANT SELECT, INSERT ON TABLE workflow_versions, workflow_events, workflow_submission_snapshots TO :runtime_role;
REVOKE UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE
    workflow_versions, workflow_events, workflow_submission_snapshots FROM :runtime_role;
GRANT USAGE ON SEQUENCE
    users_id_seq, jobs_id_seq, failed_jobs_id_seq, organizations_id_seq,
    organization_memberships_id_seq, organization_invitations_id_seq,
    workflows_id_seq, workflow_drafts_id_seq, workflow_versions_id_seq,
    workflow_instances_id_seq, workflow_tasks_id_seq, workflow_events_id_seq,
    workflow_revision_counters_id_seq, idempotency_records_id_seq,
    notification_outbox_id_seq, workflow_delays_id_seq, workflow_submission_snapshots_id_seq
TO :runtime_role;
