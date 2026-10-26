<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeTest extends TestCase
{
    use InteractsWithSpa, RefreshDatabase;

    public function test_guests_cannot_load_the_current_user(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
