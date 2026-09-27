<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Generic outbound email used by App\Services\AI\Communication\Providers\MailEmailProvider for
 * Copilot-drafted, human-approved candidate communication (spec section 38) — the body is always
 * plain text the user reviewed before sending, never raw model output sent unattended.
 */
/**
 * Phase 8.7 (SEC-87-14, D8.7-001/017): not used by any current path (Copilot sends through
 * CommunicationService); kept safe if it ever is — encrypted payload, `notifications` queue.
 */
class AiCopilotEmail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $emailSubject,
        public readonly string $body,
    ) {
        $this->onQueue('notifications');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ai-copilot');
    }
}
