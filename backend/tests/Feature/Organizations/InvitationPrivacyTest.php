<?php

namespace Tests\Feature\Organizations;

use App\Logging\RedactInvitationTokens;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvitationPrivacyTest extends TestCase
{
    use InteractsWithOrganizations, RefreshDatabase;

    public function test_invitation_tokens_are_redacted_from_logs(): void
    {
        $path = storage_path('framework/testing-redaction.log');

        if (is_file($path)) {
            unlink($path);
        }

        config()->set('logging.channels.redaction_test', [
            'driver' => 'single',
            'path' => $path,
            'level' => 'debug',
            'tap' => [RedactInvitationTokens::class],
        ]);
        Log::forgetChannel('redaction_test');

        Log::channel('redaction_test')->info(
            'https://localhost:3000/invitations/accept#token=supersecrettokenvalue',
            ['token' => 'supersecrettokenvalue'],
        );

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('supersecrettokenvalue', $contents);
        $this->assertStringContainsString('[redacted]', $contents);
        unlink($path);
    }

    public function test_the_log_mailer_does_not_store_the_invitation_token(): void
    {
        $path = storage_path('framework/testing-mail.log');

        if (is_file($path)) {
            unlink($path);
        }

        config()->set('logging.channels.mail_redaction', [
            'driver' => 'single',
            'path' => $path,
            'level' => 'debug',
            'tap' => [RedactInvitationTokens::class],
        ]);
        config()->set('mail.default', 'log');
        config()->set('mail.mailers.log.channel', 'mail_redaction');
        Log::forgetChannel('mail_redaction');
        Mail::purge('log');

        $owner = User::factory()->create();
        $this->signIn($owner);
        $organization = $this->createOrganization('Logged', 'logged');

        $this->postJson("/api/v1/organizations/{$organization}/invitations", [
            'email' => 'invitee@example.com',
            'role' => 'employee',
        ])->assertCreated();

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('/invitations/accept#token=[redacted]', $contents);
        $this->assertDoesNotMatchRegularExpression('/#token=(?!\[redacted\])[A-Za-z0-9_-]+/', $contents);

        $invitation = \App\Models\OrganizationInvitation::query()->firstOrFail();
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertStringNotContainsString($invitation->token_hash, $contents);
        unlink($path);

        $this->assertNotNull(Organization::query()->where('uuid', $organization)->first());
        $this->assertInstanceOf(User::class, $owner);
    }
}
