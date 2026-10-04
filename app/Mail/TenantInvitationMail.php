<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * SaaS-2: an invitation to join one organisation. It names that organisation and carries a
 * single-use link with no tenant in it — the tenant is the invitation's own, found from the token.
 * The same mail is sent whether or not the address already has an identity on the platform.
 */
class TenantInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $tenantName,
        public readonly ?string $inviterName,
        public readonly string $url,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to join {$this->tenantName} on ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.tenant-invitation');
    }
}
