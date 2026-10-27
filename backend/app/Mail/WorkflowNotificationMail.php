<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class WorkflowNotificationMail extends Mailable
{

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
        public readonly string $idempotencyKey,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) ($this->payload['subject'] ?? 'Workflow update'),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.workflow-notification',
            with: [
                'body' => (string) ($this->payload['body'] ?? ''),
                'instanceUuid' => (string) ($this->payload['instance_uuid'] ?? ''),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-FlowMV-Idempotency-Key' => $this->idempotencyKey,
                'X-FlowMV-Provider-Message-Id' => (string) ($this->payload['provider_message_id'] ?? ''),
            ],
        );
    }
}
