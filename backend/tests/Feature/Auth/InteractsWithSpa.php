<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Auth;

trait InteractsWithSpa
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
            'Accept' => 'application/json',
        ]);
    }

    /**
     * Drop guards cached during the previous request so the next call
     * authenticates only from the session cookie.
     */
    protected function refreshGuards(): void
    {
        Auth::shouldUse('web');
        $this->app['auth']->forgetGuards();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides);
    }
}
