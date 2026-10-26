<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use InteractsWithSpa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
            'Accept' => 'application/json',
        ]);
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'ada@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/register', $this->registrationPayload([
                'email' => "ada{$attempt}@example.com",
            ]))->assertCreated();
        }

        $this->postJson('/api/v1/auth/register', $this->registrationPayload([
            'email' => 'ada-limited@example.com',
        ]))->assertTooManyRequests();
    }
}
