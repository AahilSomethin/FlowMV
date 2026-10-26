<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use InteractsWithSpa, RefreshDatabase;

    public function test_a_visitor_can_register_and_load_the_current_user(): void
    {
        $before = $this->get('/sanctum/csrf-cookie')->getCookie(config('session.cookie'));

        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.name', 'Ada Lovelace');

        $payload = $response->json('data');
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
        $this->assertNotSame(
            $before?->getValue(),
            $response->getCookie(config('session.cookie'))?->getValue(),
        );

        $this->refreshGuards();

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'ada@example.com');
    }

    public function test_duplicate_registration_is_rejected(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(1, User::query()->where('email', 'ada@example.com')->count());
    }

    public function test_registration_validation_failures_are_json(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }
}
