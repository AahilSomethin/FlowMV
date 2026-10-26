<?php

namespace Tests\Feature\Organizations;

use App\Mail\OrganizationInvitationMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Auth\InteractsWithSpa;

trait InteractsWithOrganizations
{
    use InteractsWithSpa;

    protected function signIn(User $user): void
    {
        $this->postJson('/api/v1/auth/logout');
        $this->refreshGuards();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->refreshGuards();
    }

    protected function createOrganization(string $name = 'Acme', ?string $slug = null): string
    {
        $payload = ['name' => $name];

        if ($slug !== null) {
            $payload['slug'] = $slug;
        }

        return $this->postJson('/api/v1/organizations', $payload)
            ->assertCreated()
            ->json('data.uuid');
    }

    protected function invitationToken(string $organization, string $email, string $role = 'employee'): string
    {
        Mail::fake();

        $response = $this->postJson("/api/v1/organizations/{$organization}/invitations", [
            'email' => $email,
            'role' => $role,
        ])->assertCreated();

        $mail = Mail::sent(OrganizationInvitationMail::class)->last();
        $this->assertInstanceOf(OrganizationInvitationMail::class, $mail);
        $this->assertStringContainsString('/invitations/accept#token=', $mail->acceptUrl());
        $this->assertStringContainsString('/invitations/accept#token=', $mail->render());
        $this->assertStringNotContainsString('token_hash', (string) json_encode($response->json()));

        $params = [];
        parse_str((string) parse_url($mail->acceptUrl(), PHP_URL_FRAGMENT), $params);
        $token = $params['token'] ?? null;
        $this->assertIsString($token);
        $this->assertStringNotContainsString($token, (string) json_encode($response->json()));

        return $token;
    }
}
