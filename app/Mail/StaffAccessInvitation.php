<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 8.4: sent when a login is provisioned (candidate conversion) or restored by a rehire — a
 * single-use link to set a password. The account never has a password anyone else knows.
 */
class StaffAccessInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly int $validMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' access');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.staff-access-invitation');
    }
}
