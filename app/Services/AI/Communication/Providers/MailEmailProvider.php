<?php

namespace App\Services\AI\Communication\Providers;

use App\Mail\AiCopilotEmail;
use App\Services\AI\Communication\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Mail;

/**
 * Uses Laravel's own Mail configuration (already set up for this app — see config/mail.php,
 * MAIL_MAILER) rather than introducing a separate transactional-email vendor. In this environment
 * MAIL_MAILER=log, so sends land in the log for inspection instead of a real inbox.
 */
class MailEmailProvider implements EmailProviderInterface
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $to, string $subject, string $body): bool
    {
        Mail::to($to)->send(new AiCopilotEmail($subject, $body));

        return true;
    }
}
