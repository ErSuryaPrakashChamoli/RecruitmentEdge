<?php

namespace App\Services\Communication\Providers;

use App\Enums\CommunicationChannel;
use App\Mail\CandidateMessageMail;
use App\Services\Communication\Contracts\CommunicationProvider;
use App\Services\Communication\Data\DeliveryResult;
use App\Services\Communication\Data\IntegrationTestResult;
use App\Services\Communication\Data\OutboundMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Email through the application's own Laravel mail configuration (SMTP, SES, Postmark, … — see
 * config/mail.php). With the `log`/`array` mailers nothing leaves the server, so
 * deliversExternally() is false and the UI says so rather than claiming delivery.
 */
class LaravelMailEmailProvider implements CommunicationProvider
{
    public function key(): string
    {
        return 'mail';
    }

    public function label(): string
    {
        return 'Email (Laravel mail: '.config('mail.default').')';
    }

    public function category(): string
    {
        return 'communication';
    }

    public function channel(): CommunicationChannel
    {
        return CommunicationChannel::Email;
    }

    public function isConfigured(): bool
    {
        return filled(config('mail.default')) && filled(config('mail.from.address'));
    }

    public function deliversExternally(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        try {
            $sent = Mail::to($message->recipient)->send(new CandidateMessageMail((string) $message->subject, $message->body, $message->reference));

            return DeliveryResult::sent($sent?->getMessageId());
        } catch (TransportExceptionInterface $e) {
            return DeliveryResult::failed('Mail transport error: '.$e->getMessage(), retryable: true);
        } catch (Throwable $e) {
            return DeliveryResult::failed($e->getMessage(), retryable: false);
        }
    }

    public function testConnection(): IntegrationTestResult
    {
        if (! $this->isConfigured()) {
            return new IntegrationTestResult(false, 'Mail is not configured (MAIL_MAILER / MAIL_FROM_ADDRESS).');
        }

        if (! $this->deliversExternally()) {
            return new IntegrationTestResult(false, 'The "'.config('mail.default').'" mailer does not deliver email outside this server. Configure SMTP or another transport to send real email.');
        }

        try {
            $transport = Mail::mailer()->getSymfonyTransport();

            if (method_exists($transport, 'start')) {
                $transport->start();
            }

            return new IntegrationTestResult(true, 'Connected to the '.config('mail.default').' mail transport.');
        } catch (Throwable $e) {
            return new IntegrationTestResult(false, 'Mail transport connection failed: '.$e->getMessage());
        }
    }
}
