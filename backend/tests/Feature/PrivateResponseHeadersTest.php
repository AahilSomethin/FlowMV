<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Organizations\InteractsWithOrganizations;
use Tests\TestCase;

class PrivateResponseHeadersTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_authenticated_session_metadata_is_never_stored_by_shared_or_browser_caches(): void
    {
        $this->signIn(User::factory()->create());
        $response = $this->getJson('/api/v1/me')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
