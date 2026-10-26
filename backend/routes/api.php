<?php

use App\Http\Controllers\Api\V1\AuthController;
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

    Route::get('me', [AuthController::class, 'me'])
        ->middleware('auth:sanctum');
});
