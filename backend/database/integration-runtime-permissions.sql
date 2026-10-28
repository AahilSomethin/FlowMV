-- Apply only after the forward integration migration, as the object owner.
-- Substitute an independently provisioned restricted application role.
-- No credentials, role ownership changes, or PUBLIC grants are included.
GRANT SELECT ON TABLE integration_providers TO :runtime_role;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE
    integration_policies, integration_approvals, connections,
    credential_secrets, workflow_connection_grants, authorized_destinations,
    integration_oauth_requests
TO :runtime_role;
GRANT SELECT, INSERT ON TABLE integration_audit_events, integration_receipts TO :runtime_role;
REVOKE UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE
    integration_audit_events, integration_receipts FROM :runtime_role;
GRANT USAGE ON SEQUENCE
    integration_approvals_id_seq, workflow_connection_grants_id_seq,
    authorized_destinations_id_seq, integration_audit_events_id_seq,
    integration_receipts_id_seq
TO :runtime_role;
