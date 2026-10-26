<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use InteractsWithSpa, RefreshDatabase;

    public function test_logout_invalidates_the_session(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload())->assertCreated();

        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->refreshGuards();

        $this->assertGuest();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
