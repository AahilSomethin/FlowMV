# Generated route inventory

Generated from `php artisan route:list --json --except-vendor --env=demo` on 2026-11-05. Route presence is not acceptance evidence. See the API guides for authorization and payload handling.

| Method | URI | Controller |
| --- | --- | --- |
| GET / HEAD | `/` | `Closure` |
| POST | `api/v1/auth/login` | `AuthController@login` |
| POST | `api/v1/auth/logout` | `AuthController@logout` |
| POST | `api/v1/auth/register` | `AuthController@register` |
| POST | `api/v1/email/verification-notification` | `EmailVerificationController@store` |
| GET / HEAD | `api/v1/email/verify/{id}/{hash}` | `EmailVerificationController@verify` |
| POST | `api/v1/integration-auth/confirm` | `ConnectionController@confirm` |
| POST | `api/v1/integration-webhooks/email/{provider}` | `EmailIntegrationController@webhook` |
| POST | `api/v1/integration-webhooks/local-mock/{connection}` | `ConnectionController@webhook` |
| POST | `api/v1/integration-webhooks/slack/{kind}` | `SlackController@webhook` |
| POST | `api/v1/integration-webhooks/sms/{connection}/{delivery}` | `SmsController@webhook` |
| POST | `api/v1/integration-webhooks/telegram/{connection}` | `TelegramController@webhook` |
| POST | `api/v1/invitations/accept` | `AcceptInvitationController` |
| GET / HEAD | `api/v1/me` | `AuthController@me` |
| GET / HEAD | `api/v1/notifications` | `NotificationController@index` |
| POST | `api/v1/notifications/{notification}/read` | `NotificationController@markRead` |
| GET / HEAD | `api/v1/organizations` | `OrganizationController@index` |
| POST | `api/v1/organizations` | `OrganizationController@store` |
| GET / HEAD | `api/v1/organizations/{organization}/analytics` | `AnalyticsController@show` |
| GET / HEAD | `api/v1/organizations/{organization}/analytics/export` | `AnalyticsController@export` |
| GET / HEAD | `api/v1/organizations/{organization}/audit` | `AuditController@index` |
| GET / HEAD | `api/v1/organizations/{organization}/audit/export` | `AuditController@export` |
| GET / HEAD | `api/v1/organizations/{organization}/connections` | `ConnectionController@index` |
| POST | `api/v1/organizations/{organization}/connections` | `ConnectionController@store` |
| GET / HEAD | `api/v1/organizations/{organization}/connections/overview` | `ConnectionOverviewController` |
| POST | `api/v1/organizations/{organization}/connections/{connection}/{action}` | `ConnectionController@action` |
| GET / HEAD | `api/v1/organizations/{organization}/demo-status` | `DemoStatusController` |
| GET / HEAD | `api/v1/organizations/{organization}/instances` | `WorkflowInstanceController@index` |
| GET / HEAD | `api/v1/organizations/{organization}/instances/{instance}` | `WorkflowInstanceController@show` |
| POST | `api/v1/organizations/{organization}/instances/{instance}/retry` | `WorkflowInstanceController@retry` |
| POST | `api/v1/organizations/{organization}/integration-oauth/{stage}` | `ConnectionController@oauth` |
| PUT | `api/v1/organizations/{organization}/integration-policy` | `ConnectionController@policy` |
| GET / HEAD | `api/v1/organizations/{organization}/integrations/ai` | `AiController@index` |
| POST | `api/v1/organizations/{organization}/integrations/ai/analyze` | `AiController@analyze` |
| POST | `api/v1/organizations/{organization}/integrations/ai/connections` | `AiController@store` |
| POST | `api/v1/organizations/{organization}/integrations/ai/connections/{connection}/{action}` | `AiController@action` |
| PUT | `api/v1/organizations/{organization}/integrations/ai/policy` | `AiController@policy` |
| GET / HEAD | `api/v1/organizations/{organization}/integrations/ai/requests/{instance}` | `AiController@requestAdvice` |
| GET / HEAD | `api/v1/organizations/{organization}/integrations/ai/runs/{run}` | `AiController@result` |
| GET / HEAD | `api/v1/organizations/{organization}/integrations/email` | `EmailIntegrationController@index` |
| POST | `api/v1/organizations/{organization}/integrations/email/documents` | `EmailIntegrationController@upload` |
| GET / HEAD | `api/v1/organizations/{organization}/integrations/email/documents/{document}/download` | `EmailIntegrationController@downloadDocument` |
| POST | `api/v1/organizations/{organization}/integrations/email/documents/{document}/inspect` | `EmailIntegrationController@inspectDocument` |
| GET / HEAD | `api/v1/organizations/{organization}/integrations/email/intakes` | `EmailIntegrationController@intakes` |
| POST | `api/v1/organizations/{organization}/integrations/email/intakes/{intake}/confirm` | `EmailIntegrationController@confirmIntake` |
| POST | `api/v1/organizations/{organization}/integrations/email/oauth/{stage}` | `EmailIntegrationController@oauth` |
| PUT | `api/v1/organizations/{organization}/integrations/email/policy` | `EmailIntegrationController@policy` |
| POST | `api/v1/organizations/{organization}/integrations/email/preview` | `EmailIntegrationController@preview` |
| POST | `api/v1/organizations/{organization}/integrations/email/simulation/{stage}` | `EmailIntegrationController@simulation` |
| GET / POST / HEAD | `api/v1/organizations/{organization}/integrations/email/templates` | `EmailIntegrationController@templates` |
| POST | `api/v1/organizations/{organization}/integrations/email/{connection}/{action}` | `EmailIntegrationController@action` |
| GET / HEAD | `api/v1/organizations/{organization}/integrations/slack` | `SlackController@index` |
| POST | `api/v1/organizations/{organization}/integrations/slack/oauth/{stage}` | `SlackController@oauth` |
| PUT | `api/v1/organizations/{organization}/integrations/slack/policy` | `SlackController@policy` |
| POST | `api/v1/organizations/{organization}/integrations/slack/simulation/{stage}` | `SlackController@simulation` |
| POST | `api/v1/organizations/{organization}/integrations/slack/{connection}/identity/{stage}` | `SlackController@identity` |
| POST | `api/v1/organizations/{organization}/integrations/slack/{connection}/{action}` | `SlackController@action` |
| GET / HEAD | `api/v1/organizations/{organization}/integrations/sms` | `SmsController@index` |
| POST | `api/v1/organizations/{organization}/integrations/sms-preview` | `SmsController@preview` |
| POST | `api/v1/organizations/{organization}/integrations/sms/connections` | `SmsController@store` |
| PUT | `api/v1/organizations/{organization}/integrations/sms/policy` | `SmsController@policy` |
| POST | `api/v1/organizations/{organization}/integrations/sms/templates` | `SmsController@template` |
| POST | `api/v1/organizations/{organization}/integrations/sms/{connection}/{action}` | `SmsController@action` |
| GET / HEAD | `api/v1/organizations/{organization}/integrations/telegram` | `TelegramController@index` |
| POST | `api/v1/organizations/{organization}/integrations/telegram` | `TelegramController@store` |
| PUT | `api/v1/organizations/{organization}/integrations/telegram/policy` | `TelegramController@policy` |
| POST | `api/v1/organizations/{organization}/integrations/telegram/{connection}/approval-simulation` | `TelegramController@approvalSimulation` |
| POST | `api/v1/organizations/{organization}/integrations/telegram/{connection}/identity-simulation` | `TelegramController@identitySimulation` |
| POST | `api/v1/organizations/{organization}/integrations/telegram/{connection}/identity/{stage}` | `TelegramController@link` |
| POST | `api/v1/organizations/{organization}/integrations/telegram/{connection}/simulation` | `TelegramController@simulate` |
| POST | `api/v1/organizations/{organization}/integrations/telegram/{connection}/{action}` | `TelegramController@action` |
| GET / HEAD | `api/v1/organizations/{organization}/invitations` | `InvitationController@index` |
| POST | `api/v1/organizations/{organization}/invitations` | `InvitationController@store` |
| DELETE | `api/v1/organizations/{organization}/invitations/{invitation}` | `InvitationController@destroy` |
| GET / HEAD | `api/v1/organizations/{organization}/members` | `MembershipController@index` |
| PATCH | `api/v1/organizations/{organization}/members/{membership}` | `MembershipController@update` |
| DELETE | `api/v1/organizations/{organization}/members/{membership}` | `MembershipController@destroy` |
| GET / HEAD | `api/v1/organizations/{organization}/notifications` | `NotificationController@index` |
| POST | `api/v1/organizations/{organization}/notifications/{notification}/read` | `NotificationController@markOrganizationRead` |
| POST | `api/v1/organizations/{organization}/switch` | `OrganizationController@switch` |
| GET / HEAD | `api/v1/organizations/{organization}/tasks` | `WorkflowTaskController@index` |
| GET / HEAD | `api/v1/organizations/{organization}/tasks/{task}` | `WorkflowTaskController@show` |
| POST | `api/v1/organizations/{organization}/tasks/{task}/complete` | `WorkflowTaskController@complete` |
| POST | `api/v1/organizations/{organization}/tasks/{task}/decisions` | `WorkflowTaskController@decide` |
| POST | `api/v1/organizations/{organization}/tasks/{task}/reassign` | `WorkflowTaskController@reassign` |
| POST | `api/v1/organizations/{organization}/tasks/{task}/revisions` | `WorkflowTaskController@revise` |
| GET / HEAD | `api/v1/organizations/{organization}/workflow-templates` | `WorkflowToolsController@templates` |
| POST | `api/v1/organizations/{organization}/workflow-templates/{template}` | `WorkflowToolsController@instantiate` |
| GET / HEAD | `api/v1/organizations/{organization}/workflows` | `WorkflowController@index` |
| POST | `api/v1/organizations/{organization}/workflows` | `WorkflowController@store` |
| GET / HEAD | `api/v1/organizations/{organization}/workflows/{workflow}` | `WorkflowController@show` |
| PATCH | `api/v1/organizations/{organization}/workflows/{workflow}` | `WorkflowController@update` |
| POST | `api/v1/organizations/{organization}/workflows/{workflow}/archive` | `WorkflowController@archive` |
| GET / HEAD | `api/v1/organizations/{organization}/workflows/{workflow}/compare` | `WorkflowToolsController@compare` |
| PUT | `api/v1/organizations/{organization}/workflows/{workflow}/draft` | `WorkflowDraftController@update` |
| POST | `api/v1/organizations/{organization}/workflows/{workflow}/draft/validate` | `WorkflowDraftController@validateDraft` |
| POST | `api/v1/organizations/{organization}/workflows/{workflow}/instances` | `WorkflowInstanceController@store` |
| POST | `api/v1/organizations/{organization}/workflows/{workflow}/preview` | `WorkflowToolsController@preview` |
| POST | `api/v1/organizations/{organization}/workflows/{workflow}/publish` | `WorkflowVersionController@store` |
| GET / HEAD | `api/v1/organizations/{organization}/workflows/{workflow}/versions` | `WorkflowVersionController@index` |
| GET / HEAD | `api/v1/organizations/{organization}/workflows/{workflow}/versions/{version}` | `WorkflowVersionController@show` |
