<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * A rendered candidate communication sent as email. The body is plain text produced by
 * TemplateRenderer (never Blade-compiled user content); the markdown view escapes it.
 */
class CandidateMessageMail extends Mailable
{
    public function __construct(
        public readonly string $messageSubject,
        public readonly string $messageBody,
        public readonly string $reference,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->messageSubject);
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Recruitment-Edge-Reference' => $this->reference]);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.candidate-message');
    }
}
