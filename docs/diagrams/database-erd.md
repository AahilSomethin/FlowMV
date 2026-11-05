# Database ERDs by subsystem

Relationships are derived from migrations. These are major entities, not a column-complete schema. UUID references and composite organization constraints supplement the shown keys.

## Organization and workflow execution

```mermaid
erDiagram
  direction LR
  users ||--o{ organization_memberships : member
  organizations ||--o{ organization_memberships : scopes
  organizations ||--o{ organization_invitations : invites
  organizations ||--o{ workflows : owns
  workflows ||--|| workflow_drafts : mutable_draft
  workflows ||--o{ workflow_versions : publishes
  workflow_versions ||--o{ workflow_instances : pins
  organizations ||--o{ workflow_instances : scopes
  users o|--o{ workflow_instances : submits
  workflow_instances ||--o{ workflow_tasks : assigns
  workflow_instances ||--o{ workflow_events : records
  workflow_instances ||--o{ workflow_submission_snapshots : captures
  workflow_tasks o|--o{ workflow_submission_snapshots : decision
  workflow_instances ||--o{ workflow_delays : waits
  workflow_instances ||--o{ notification_outbox : queues
  organizations ||--o| demo_datasets : owns_showcase
```

## Credentials and Telegram

```mermaid
erDiagram
  direction LR
  integration_providers ||--o{ connections : provider
  organizations ||--o{ connections : scopes
  users o|--o{ connections : personal_owner
  connections ||--o| credential_secrets : encrypted
  connections ||--o{ workflow_connection_grants : permits
  workflows ||--o{ workflow_connection_grants : uses
  connections ||--o{ authorized_destinations : restricts
  connections o|--o{ integration_audit_events : evidence
  connections ||--o| telegram_bots : identity
  connections ||--o{ telegram_destinations : approves
  connections ||--o{ telegram_identity_links : links
  connections ||--o{ integration_trigger_subscriptions : subscribes
  workflow_versions ||--o{ integration_trigger_subscriptions : pins
  integration_trigger_subscriptions ||--o{ integration_trigger_activations : activates
  workflow_tasks ||--o{ telegram_approval_deliveries : prompts
  telegram_approval_deliveries ||--o{ telegram_decision_capabilities : expires
  connections ||--o{ telegram_decision_callbacks : deduplicates
```

## Email and documents

```mermaid
erDiagram
  direction LR
  connections ||--o| email_mailboxes : mailbox
  connections ||--o| email_sync_state : cursor
  connections ||--o{ email_workflow_grants : scopes
  connections ||--o{ email_messages : receives
  email_messages ||--o{ email_attachment_fetches : fetches
  email_messages o|--o{ private_documents : evidence
  email_messages ||--o{ email_intakes : confirms
  email_trigger_bindings ||--o{ email_intakes : routes
  workflow_instances ||--o{ workflow_document_evidence : links
  private_documents ||--o{ workflow_document_evidence : links
  workflow_instances ||--o{ email_action_outbox : replies
```

## Slack

```mermaid
erDiagram
  direction LR
  connections ||--o| slack_installations : workspace
  connections ||--o{ slack_channels : approves
  connections ||--o{ slack_workflow_grants : permits
  slack_trigger_subscriptions ||--o{ slack_trigger_activations : activates
  slack_inbound_events ||--o{ slack_trigger_activations : deduplicates
  workflow_instances ||--o{ slack_action_outbox : sends
```

## SMS

```mermaid
erDiagram
  direction LR
  connections ||--o| sms_accounts : account
  connections ||--o{ sms_senders : owns
  connections ||--o{ sms_recipients : consents
  sms_templates ||--o{ sms_workflow_grants : permits
  workflow_instances ||--o{ sms_action_outbox : sends
  sms_action_outbox ||--o{ sms_status_receipts : records
```

## Advisory AI

```mermaid
erDiagram
  direction LR
  organizations ||--o| ai_policies : controls
  connections ||--o| ai_connections : configures
  workflows ||--o{ ai_workflow_grants : permits
  connections ||--o{ ai_workflow_grants : scopes
  workflow_instances ||--o{ ai_processing_runs : references
  ai_processing_runs ||--|| ai_usage_reservations : reserves
  organizations ||--o{ ai_demo_scenarios : owns
```

AI tables come from the AI processing foundation and demo-scenario migrations. Attribute-level detail and trust-boundary diagrams live in [AI processing](ai-processing.md).
