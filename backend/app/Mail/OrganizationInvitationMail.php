<?php

namespace App\Mail;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrganizationInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Organization $organization,
        public string $role,
        #[\SensitiveParameter] private string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Join '.$this->organization->name.' on FlowMV',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.organization-invitation',
            with: [
                'organizationName' => $this->organization->name,
                'roleLabel' => str($this->role)->title()->toString(),
                'acceptUrl' => $this->acceptUrl(),
            ],
        );
    }

    public function acceptUrl(): string
    {
        return rtrim((string) config('flowmv.frontend_url'), '/')
            .'/invitations/accept#token='
            .rawurlencode($this->token);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'organization' => $this->organization->uuid,
            'role' => $this->role,
            'token' => '[redacted]',
        ];
    }
}
