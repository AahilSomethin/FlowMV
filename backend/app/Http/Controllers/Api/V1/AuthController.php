<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\LoginUser;
use App\Actions\Auth\LogoutUser;
use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterUser $register): JsonResponse
    {
        return (new UserResource($register($request)))
            ->response()
            ->setStatusCode(201);
    }

    public function login(LoginRequest $request, LoginUser $login): UserResource
    {
        return new UserResource($login($request));
    }

    public function logout(Request $request, LogoutUser $logout): Response
    {
        $logout($request);

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return (new UserResource($request->user()))
            ->response()
            ->setStatusCode(200);
    }
}
