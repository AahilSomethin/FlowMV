<?php

use App\Http\Controllers\Api\V1\AcceptInvitationController;
use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConnectionController;
use App\Http\Controllers\Api\V1\ConnectionOverviewController;
use App\Http\Controllers\Api\V1\DemoStatusController;
use App\Http\Controllers\Api\V1\EmailIntegrationController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\SlackController;
use App\Http\Controllers\Api\V1\SmsController;
use App\Http\Controllers\Api\V1\TelegramController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Http\Controllers\Api\V1\WorkflowDraftController;
use App\Http\Controllers\Api\V1\WorkflowInstanceController;
use App\Http\Controllers\Api\V1\WorkflowTaskController;
use App\Http\Controllers\Api\V1\WorkflowToolsController;
use App\Http\Controllers\Api\V1\WorkflowVersionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('integration-webhooks/sms/{connection}/{delivery}', [SmsController::class, 'webhook'])->whereUuid(['connection', 'delivery'])->middleware('throttle:120,1');
    Route::post('integration-webhooks/slack/{kind}', [SlackController::class, 'webhook'])->middleware('throttle:120,1');
    Route::post('integration-webhooks/email/{provider}', [EmailIntegrationController::class, 'webhook'])->middleware('throttle:120,1');
    Route::post('integration-webhooks/telegram/{connection}', [TelegramController::class, 'webhook'])->middleware('throttle:60,1');
    Route::post('integration-webhooks/local-mock/{connection}', [ConnectionController::class, 'webhook'])->middleware('throttle:30,1');
    Route::middleware('stateful.frontend')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])
            ->middleware('throttle:register');

        Route::post('auth/login', [AuthController::class, 'login'])
            ->middleware('throttle:login');

        Route::post('auth/logout', [AuthController::class, 'logout'])
            ->middleware('auth:sanctum');
    });

    Route::middleware(['auth:sanctum', 'sync.organization'])->group(function () {
        Route::get('organizations/{organization}/demo-status', DemoStatusController::class);
        Route::middleware(['stateful.frontend', 'throttle:30,1'])->group(function () {
            Route::post('organizations/{organization}/integrations/slack/simulation/{stage}', [SlackController::class, 'simulation']);
            Route::get('organizations/{organization}/integrations/sms', [SmsController::class, 'index']);
            Route::put('organizations/{organization}/integrations/sms/policy', [SmsController::class, 'policy']);
            Route::post('organizations/{organization}/integrations/sms/connections', [SmsController::class, 'store']);
            Route::post('organizations/{organization}/integrations/sms/templates', [SmsController::class, 'template']);
            Route::post('organizations/{organization}/integrations/sms/{connection}/{action}', [SmsController::class, 'action']);
            Route::post('organizations/{organization}/integrations/sms-preview', [SmsController::class, 'preview']);
            Route::get('organizations/{organization}/integrations/slack', [SlackController::class, 'index']);
            Route::put('organizations/{organization}/integrations/slack/policy', [SlackController::class, 'policy']);
            Route::post('organizations/{organization}/integrations/slack/oauth/{stage}', [SlackController::class, 'oauth']);
            Route::post('organizations/{organization}/integrations/slack/{connection}/identity/{stage}', [SlackController::class, 'identity']);
            Route::post('organizations/{organization}/integrations/slack/{connection}/{action}', [SlackController::class, 'action']);
            Route::post('organizations/{organization}/integrations/email/simulation/{stage}', [EmailIntegrationController::class, 'simulation']);
            Route::post('organizations/{organization}/integrations/email/preview', [EmailIntegrationController::class, 'preview'])->middleware('throttle:10,1');
            Route::get('organizations/{organization}/integrations/email/intakes', [EmailIntegrationController::class, 'intakes']);
            Route::post('organizations/{organization}/integrations/email/intakes/{intake}/confirm', [EmailIntegrationController::class, 'confirmIntake']);
            Route::post('organizations/{organization}/integrations/email/documents', [EmailIntegrationController::class, 'upload'])->middleware('throttle:10,1');
            Route::post('organizations/{organization}/integrations/email/documents/{document}/inspect', [EmailIntegrationController::class, 'inspectDocument'])->middleware('throttle:10,1');
            Route::get('organizations/{organization}/integrations/email/documents/{document}/download', [EmailIntegrationController::class, 'downloadDocument']);
            Route::match(['GET', 'POST'], 'organizations/{organization}/integrations/email/templates', [EmailIntegrationController::class, 'templates']);
            Route::get('organizations/{organization}/integrations/email', [EmailIntegrationController::class, 'index']);
            Route::put('organizations/{organization}/integrations/email/policy', [EmailIntegrationController::class, 'policy']);
            Route::post('organizations/{organization}/integrations/email/oauth/{stage}', [EmailIntegrationController::class, 'oauth']);
            Route::post('organizations/{organization}/integrations/email/{connection}/{action}', [EmailIntegrationController::class, 'action']);
            Route::get('organizations/{organization}/integrations/telegram', [TelegramController::class, 'index']);
            Route::put('organizations/{organization}/integrations/telegram/policy', [TelegramController::class, 'policy']);
            Route::post('organizations/{organization}/integrations/telegram', [TelegramController::class, 'store']);
            Route::post('organizations/{organization}/integrations/telegram/{connection}/simulation', [TelegramController::class, 'simulate']);
            Route::post('organizations/{organization}/integrations/telegram/{connection}/approval-simulation', [TelegramController::class, 'approvalSimulation']);
            Route::post('organizations/{organization}/integrations/telegram/{connection}/identity-simulation', [TelegramController::class, 'identitySimulation']);
            Route::post('organizations/{organization}/integrations/telegram/{connection}/identity/{stage}', [TelegramController::class, 'link']);
            Route::post('organizations/{organization}/integrations/telegram/{connection}/{action}', [TelegramController::class, 'action']);
            Route::post('integration-auth/confirm', [ConnectionController::class, 'confirm'])->middleware('throttle:integration-step-up');
            Route::get('organizations/{organization}/integrations/ai', [AiController::class, 'index']);
            Route::put('organizations/{organization}/integrations/ai/policy', [AiController::class, 'policy']);
            Route::post('organizations/{organization}/integrations/ai/connections', [AiController::class, 'store']);
            Route::post('organizations/{organization}/integrations/ai/analyze', [AiController::class, 'analyze'])->middleware('throttle:10,1');
            Route::get('organizations/{organization}/integrations/ai/runs/{run}', [AiController::class, 'result'])->whereUuid('run');
            Route::get('/organizations/{organization}/integrations/ai/requests/{instance}', [AiController::class, 'requestAdvice']);
            Route::post('organizations/{organization}/integrations/ai/connections/{connection}/{action}', [AiController::class, 'action'])->whereUuid('connection');
            Route::get('organizations/{organization}/connections/overview', ConnectionOverviewController::class);
            Route::get('organizations/{organization}/connections', [ConnectionController::class, 'index']);
            Route::post('organizations/{organization}/connections', [ConnectionController::class, 'store']);
            Route::put('organizations/{organization}/integration-policy', [ConnectionController::class, 'policy']);
            Route::post('organizations/{organization}/integration-oauth/{stage}', [ConnectionController::class, 'oauth']);
            Route::post('organizations/{organization}/connections/{connection}/{action}', [ConnectionController::class, 'action']);
        });
        Route::get('me', [AuthController::class, 'me']);

        Route::get('organizations', [OrganizationController::class, 'index']);
        Route::get('organizations/{organization}/members', [MembershipController::class, 'index']);
        Route::get('organizations/{organization}/invitations', [InvitationController::class, 'index']);
        Route::get('organizations/{organization}/workflow-templates', [WorkflowToolsController::class, 'templates']);
        Route::get('organizations/{organization}/workflows', [WorkflowController::class, 'index']);
        Route::get('organizations/{organization}/workflows/{workflow}', [WorkflowController::class, 'show']);
        Route::get('organizations/{organization}/workflows/{workflow}/compare', [WorkflowToolsController::class, 'compare']);
        Route::get('organizations/{organization}/workflows/{workflow}/versions', [WorkflowVersionController::class, 'index']);
        Route::get('organizations/{organization}/workflows/{workflow}/versions/{version}', [WorkflowVersionController::class, 'show']);
        Route::get('organizations/{organization}/instances', [WorkflowInstanceController::class, 'index']);
        Route::get('organizations/{organization}/instances/{instance}', [WorkflowInstanceController::class, 'show']);
        Route::get('organizations/{organization}/tasks', [WorkflowTaskController::class, 'index']);
        Route::get('organizations/{organization}/tasks/{task}', [WorkflowTaskController::class, 'show']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('organizations/{organization}/notifications', [NotificationController::class, 'index']);
        Route::get('organizations/{organization}/analytics', [AnalyticsController::class, 'show']);
        Route::get('organizations/{organization}/analytics/export', [AnalyticsController::class, 'export']);
        Route::get('organizations/{organization}/audit', [AuditController::class, 'index']);
        Route::get('organizations/{organization}/audit/export', [AuditController::class, 'export']);

        Route::middleware('stateful.frontend')->group(function () {
            Route::post('organizations', [OrganizationController::class, 'store']);
            Route::post('organizations/{organization}/workflow-templates/{template}', [WorkflowToolsController::class, 'instantiate'])->middleware('throttle:10,1');
            Route::post('organizations/{organization}/workflows', [WorkflowController::class, 'store']);
            Route::patch('organizations/{organization}/workflows/{workflow}', [WorkflowController::class, 'update']);
            Route::post('organizations/{organization}/workflows/{workflow}/preview', [WorkflowToolsController::class, 'preview'])->middleware('throttle:10,1');
            Route::put('organizations/{organization}/workflows/{workflow}/draft', [WorkflowDraftController::class, 'update']);
            Route::post('organizations/{organization}/workflows/{workflow}/draft/validate', [WorkflowDraftController::class, 'validateDraft']);
            Route::post('organizations/{organization}/workflows/{workflow}/publish', [WorkflowVersionController::class, 'store']);
            Route::post('organizations/{organization}/workflows/{workflow}/archive', [WorkflowController::class, 'archive']);
            Route::post('organizations/{organization}/workflows/{workflow}/instances', [WorkflowInstanceController::class, 'store']);
            Route::post('organizations/{organization}/instances/{instance}/retry', [WorkflowInstanceController::class, 'retry']);
            Route::post('organizations/{organization}/tasks/{task}/decisions', [WorkflowTaskController::class, 'decide']);
            Route::post('organizations/{organization}/tasks/{task}/revisions', [WorkflowTaskController::class, 'revise']);
            Route::post('organizations/{organization}/tasks/{task}/complete', [WorkflowTaskController::class, 'complete']);
            Route::post('organizations/{organization}/tasks/{task}/reassign', [WorkflowTaskController::class, 'reassign']);
            Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
            Route::post('organizations/{organization}/notifications/{notification}/read', [NotificationController::class, 'markOrganizationRead']);
            Route::post('organizations/{organization}/switch', [OrganizationController::class, 'switch']);

            Route::patch('organizations/{organization}/members/{membership}', [MembershipController::class, 'update']);
            Route::delete('organizations/{organization}/members/{membership}', [MembershipController::class, 'destroy']);

            Route::post('organizations/{organization}/invitations', [InvitationController::class, 'store'])
                ->middleware('throttle:invitations');
            Route::delete('organizations/{organization}/invitations/{invitation}', [InvitationController::class, 'destroy']);

            Route::post('invitations/accept', AcceptInvitationController::class)
                ->middleware('throttle:invitation-accept');

            Route::post('email/verification-notification', [EmailVerificationController::class, 'store'])
                ->middleware('throttle:verification-send');
        });
    });

    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['auth:sanctum', 'signed', 'throttle:6,1'])
        ->name('api.v1.email.verify');
});
