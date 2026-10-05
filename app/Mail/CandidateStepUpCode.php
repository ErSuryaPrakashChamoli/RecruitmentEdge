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
 * Phase 8.8 (D8.8-001): the email one-time code for a candidate portal step-up. Email is the only
 * approved channel. Queued on `security` (Phase 8.9: its own worker) and encrypted, because the payload carries the code
 * (the code is stored nowhere else in plaintext).
 */
class CandidateStepUpCode extends Mailable implements ShouldBeEncrypted, ShouldQueue
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
        public readonly string $code,
        public readonly int $validMinutes,
    ) {
        $this->onQueue('security');
        $this->organisationName = Branding::tenantName();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->organisationName} verification code");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.candidate-step-up-code');
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('queue.mail_failed', ['mail' => static::class, 'error' => $exception !== null ? $exception::class : null]);
    }
}
