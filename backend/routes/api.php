<?php

use App\Http\Controllers\Api\V1\AcceptInvitationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Http\Controllers\Api\V1\WorkflowDraftController;
use App\Http\Controllers\Api\V1\WorkflowInstanceController;
use App\Http\Controllers\Api\V1\WorkflowTaskController;
use App\Http\Controllers\Api\V1\WorkflowVersionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('stateful.frontend')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])
            ->middleware('throttle:register');

        Route::post('auth/login', [AuthController::class, 'login'])
            ->middleware('throttle:login');

        Route::post('auth/logout', [AuthController::class, 'logout'])
            ->middleware('auth:sanctum');
    });

    Route::middleware(['auth:sanctum', 'sync.organization'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);

        Route::get('organizations', [OrganizationController::class, 'index']);
        Route::get('organizations/{organization}/members', [MembershipController::class, 'index']);
        Route::get('organizations/{organization}/invitations', [InvitationController::class, 'index']);
        Route::get('organizations/{organization}/workflows', [WorkflowController::class, 'index']);
        Route::get('organizations/{organization}/workflows/{workflow}', [WorkflowController::class, 'show']);
        Route::get('organizations/{organization}/workflows/{workflow}/versions', [WorkflowVersionController::class, 'index']);
        Route::get('organizations/{organization}/workflows/{workflow}/versions/{version}', [WorkflowVersionController::class, 'show']);
        Route::get('organizations/{organization}/instances', [WorkflowInstanceController::class, 'index']);
        Route::get('organizations/{organization}/instances/{instance}', [WorkflowInstanceController::class, 'show']);
        Route::get('organizations/{organization}/tasks', [WorkflowTaskController::class, 'index']);
        Route::get('organizations/{organization}/tasks/{task}', [WorkflowTaskController::class, 'show']);

        Route::middleware('stateful.frontend')->group(function () {
            Route::post('organizations', [OrganizationController::class, 'store']);
            Route::post('organizations/{organization}/workflows', [WorkflowController::class, 'store']);
            Route::patch('organizations/{organization}/workflows/{workflow}', [WorkflowController::class, 'update']);
            Route::put('organizations/{organization}/workflows/{workflow}/draft', [WorkflowDraftController::class, 'update']);
            Route::post('organizations/{organization}/workflows/{workflow}/draft/validate', [WorkflowDraftController::class, 'validateDraft']);
            Route::post('organizations/{organization}/workflows/{workflow}/publish', [WorkflowVersionController::class, 'store']);
            Route::post('organizations/{organization}/workflows/{workflow}/archive', [WorkflowController::class, 'archive']);
            Route::post('organizations/{organization}/workflows/{workflow}/instances', [WorkflowInstanceController::class, 'store']);
            Route::post('organizations/{organization}/instances/{instance}/retry', [WorkflowInstanceController::class, 'retry']);
            Route::post('organizations/{organization}/tasks/{task}/decisions', [WorkflowTaskController::class, 'decide']);
            Route::post('organizations/{organization}/tasks/{task}/revisions', [WorkflowTaskController::class, 'revise']);
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
