-- Apply only after the forward integration migration, as the object owner.
-- Substitute an independently provisioned restricted application role.
-- No credentials, role ownership changes, or PUBLIC grants are included.
GRANT SELECT ON TABLE integration_providers TO :runtime_role;
-- Phase 9D.1, after the additive Slack migration.
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE slack_installations, slack_oauth_attempts,
    slack_channels, slack_workflow_grants, slack_identity_links, slack_inbound_events,
    slack_trigger_subscriptions, slack_trigger_activations, slack_action_outbox TO :runtime_role;
GRANT USAGE ON SEQUENCE slack_channels_id_seq, slack_workflow_grants_id_seq,
    slack_identity_links_id_seq TO :runtime_role;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE
    integration_policies, integration_approvals, connections,
    credential_secrets, workflow_connection_grants, authorized_destinations,
    integration_oauth_requests
TO :runtime_role;

-- Additional Phase 9B tables; apply after the Telegram forward migration.
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE
    telegram_bots, telegram_destinations, telegram_identity_links,
    integration_trigger_subscriptions, telegram_inbound_events,
    integration_trigger_activations, integration_action_outbox
TO :runtime_role;
GRANT USAGE ON SEQUENCE telegram_destinations_id_seq, telegram_identity_links_id_seq TO :runtime_role;
-- Phase 9D.2, after the decision capability forward migration.
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE telegram_approval_deliveries,
    telegram_decision_capabilities, telegram_decision_callbacks TO :runtime_role;
-- Phase 9C: apply only after its additive migration. Templates stay immutable.
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE
    email_mailboxes, email_oauth_attempts, email_sync_state, email_notification_receipts,
    email_destinations, email_workflow_grants, email_fetch_queue, email_messages,
    email_attachment_fetches, private_documents, workflow_document_evidence,
    email_trigger_bindings, email_intakes, email_action_outbox
TO :runtime_role;
GRANT SELECT, INSERT ON TABLE document_templates TO :runtime_role;
REVOKE UPDATE, DELETE, TRUNCATE ON TABLE document_templates FROM :runtime_role;
GRANT USAGE ON SEQUENCE email_notification_receipts_id_seq, email_destinations_id_seq,
    email_workflow_grants_id_seq, workflow_document_evidence_id_seq TO :runtime_role;
GRANT SELECT, INSERT ON TABLE integration_audit_events, integration_receipts TO :runtime_role;
REVOKE UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE
    integration_audit_events, integration_receipts FROM :runtime_role;
GRANT USAGE ON SEQUENCE
    integration_approvals_id_seq, workflow_connection_grants_id_seq,
    authorized_destinations_id_seq, integration_audit_events_id_seq,
    integration_receipts_id_seq
TO :runtime_role;

-- Phase 9D.3: apply only after the SMS forward migration. Template content is immutable.
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE sms_accounts, sms_policies,
    sms_senders, sms_recipients, sms_workflow_grants, sms_action_outbox TO :runtime_role;
GRANT SELECT, INSERT, UPDATE ON TABLE sms_templates TO :runtime_role;
REVOKE DELETE, TRUNCATE ON TABLE sms_templates FROM :runtime_role;
GRANT SELECT, INSERT ON TABLE sms_status_receipts TO :runtime_role;
GRANT USAGE ON SEQUENCE sms_workflow_grants_id_seq, sms_status_receipts_id_seq TO :runtime_role;

-- Phase 11: apply after both additive AI migrations, using an operator-reviewed role.
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE ai_policies, ai_connections,
    ai_workflow_grants, ai_processing_runs, ai_usage_reservations TO :runtime_role;
GRANT USAGE ON SEQUENCE ai_workflow_grants_id_seq TO :runtime_role;
-- Demo ledger creation is a demo-owner operation, never a production runtime grant.
