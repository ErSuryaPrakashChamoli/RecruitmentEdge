<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * SaaS-7 (S7-09): a deterministic failure path — three tries with backoff, then logged
     * without content (never an immediate retry storm against the mail server).
     */
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

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

    public function failed(?Throwable $exception): void
    {
        Log::warning('queue.mail_failed', ['mail' => static::class, 'error' => $exception !== null ? $exception::class : null]);
    }
}
