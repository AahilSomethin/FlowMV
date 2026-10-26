<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use InteractsWithSpa, RefreshDatabase;

    public function test_a_user_can_log_in(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        $before = $this->get('/sanctum/csrf-cookie')->getCookie(config('session.cookie'));

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonPath('data.email', 'ada@example.com');
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertAuthenticated();
        $this->assertNotSame(
            $before?->getValue(),
            $response->getCookie(config('session.cookie'))?->getValue(),
        );
    }

    public function test_invalid_credentials_use_a_generic_message(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

        $this->assertGuest();
    }

    public function test_login_validation_failures_are_json(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
