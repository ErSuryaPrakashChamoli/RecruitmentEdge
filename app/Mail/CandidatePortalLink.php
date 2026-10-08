<?php

namespace App\Mail;

use App\Services\Branding;
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
 * The candidate portal's own transactional email: an invitation or password link. Uses the app's
 * existing mail configuration — not a Phase 5 communication provider.
 *
 * Phase 8.7 (SEC-87-12): queued, so "forgot password" takes the same time whether or not the
 * email has an account; encrypted, because the payload holds the signed password link.
 */
class CandidatePortalLink extends Mailable implements ShouldBeEncrypted, ShouldQueue
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

    /**
     * SaaS-5 (S1-08): the candidate deals with the organisation, not the software it uses.
     */
    public readonly string $organisationName;

    public function __construct(
        public readonly string $candidateName,
        public readonly string $url,
        public readonly bool $isInvitation,
    ) {
        $this->onQueue('security');
        $this->organisationName = Branding::tenantName();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isInvitation ? "Your {$this->organisationName} candidate portal access" : "Set your {$this->organisationName} portal password",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.candidate-portal-link');
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('queue.mail_failed', ['mail' => static::class, 'error' => $exception !== null ? $exception::class : null]);
    }
}
