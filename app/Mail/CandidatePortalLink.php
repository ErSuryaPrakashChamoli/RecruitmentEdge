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
 * The candidate portal's own transactional email: an invitation or password link. Uses the app's
 * existing mail configuration — not a Phase 5 communication provider.
 *
 * Phase 8.7 (SEC-87-12): queued, so "forgot password" takes the same time whether or not the
 * email has an account; encrypted, because the payload holds the signed password link.
 */
class CandidatePortalLink extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $candidateName,
        public readonly string $url,
        public readonly bool $isInvitation,
    ) {
        $this->onQueue('notifications');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isInvitation ? 'Your '.config('app.name').' candidate portal access' : 'Set your '.config('app.name').' portal password',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.candidate-portal-link');
    }
}
