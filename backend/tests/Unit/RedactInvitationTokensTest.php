<?php

namespace Tests\Unit;

use App\Logging\RedactInvitationTokens;
use PHPUnit\Framework\TestCase;

class RedactInvitationTokensTest extends TestCase
{
    public function test_invitation_tokens_are_removed_from_text(): void
    {
        $redacted = RedactInvitationTokens::redact(
            'Open /invitations/accept#token=abcDEF_123-xyz and ?token=abcDEF_123-xyz {"token":"abcDEF_123-xyz"}',
        );

        $this->assertStringNotContainsString('abcDEF_123-xyz', $redacted);
        $this->assertStringContainsString('#token=[redacted]', $redacted);
        $this->assertStringContainsString('?token=[redacted]', $redacted);
    }
}
