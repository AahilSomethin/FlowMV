<?php

namespace App\Actions\Auth;

use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class RegisterUser
{
    public function __invoke(RegisterRequest $request): User
    {
        $user = User::query()->create($request->safe()->only(['name', 'email', 'password']));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $user;
    }
}
