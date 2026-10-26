<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsrfTest extends TestCase
{
    use InteractsWithSpa, RefreshDatabase;

    public function test_a_stateful_login_without_a_csrf_token_is_rejected(): void
    {
        $this->app['env'] = 'local';

        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertStatus(419);

        $this->assertGuest();
    }

    public function test_a_non_stateful_login_does_not_establish_a_session(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        $this->flushHeaders()->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertStatus(419);

        $this->assertGuest();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
