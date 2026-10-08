<?php

namespace App\Mail;

use App\Models\PlatformEvent;
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
 * SaaS-5: a critical platform event, mailed to the platform operations address. Carries the event id
 * only (encrypted on the queue); the text is read when it is sent.
 */
class PlatformEventMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('notifications');
    }

    public function envelope(): Envelope
    {
        $event = PlatformEvent::query()->find($this->eventId);

        return new Envelope(subject: '['.Branding::platformName().' platform] '.($event?->title ?? 'Platform event'));
    }

    public function content(): Content
    {
        $event = PlatformEvent::query()->with('tenant')->find($this->eventId);

        return new Content(markdown: 'mail.platform-event', with: ['event' => $event]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('queue.mail_failed', ['mail' => static::class, 'error' => $exception !== null ? $exception::class : null]);
    }
}
