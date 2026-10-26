<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use InteractsWithSpa, RefreshDatabase;

    public function test_a_signed_link_verifies_the_authenticated_user(): void
    {
        $user = User::factory()->unverified()->create([
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        $this->refreshGuards();

        $url = URL::temporarySignedRoute('api.v1.email.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_an_invalid_signature_does_not_verify_the_user(): void
    {
        $user = User::factory()->unverified()->create([
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        $this->refreshGuards();

        $this->getJson('/api/v1/email/verify/'.$user->id.'/'.sha1($user->email).'?expires=9999999999&signature=invalid')
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_signed_link_does_not_verify_a_different_user(): void
    {
        $target = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create([
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $other->email,
            'password' => 'password',
        ])->assertOk();
        $this->refreshGuards();

        $url = URL::temporarySignedRoute('api.v1.email.verify', now()->addHour(), [
            'id' => $target->id,
            'hash' => sha1($target->email),
        ]);

        $this->getJson($url)->assertForbidden();
        $this->assertNull($target->fresh()->email_verified_at);
    }
}
