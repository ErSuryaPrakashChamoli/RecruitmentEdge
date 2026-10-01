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
 * Phase 8.8 (D8.8-001): the email one-time code for a candidate portal step-up. Email is the only
 * approved channel. Queued on `notifications` and encrypted, because the payload carries the code
 * (the code is stored nowhere else in plaintext).
 */
class CandidateStepUpCode extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $candidateName,
        public readonly string $code,
        public readonly int $validMinutes,
    ) {
        $this->onQueue('notifications');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' verification code');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.candidate-step-up-code');
    }
}
