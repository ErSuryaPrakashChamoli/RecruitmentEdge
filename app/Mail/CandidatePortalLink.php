<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The candidate portal's own transactional email: an invitation or password link. Uses the app's
 * existing mail configuration — not a Phase 5 communication provider.
 */
class CandidatePortalLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $candidateName,
        public readonly string $url,
        public readonly bool $isInvitation,
    ) {}

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
