<?php

namespace App\Services\AI\Communication\Contracts;

interface EmailProviderInterface
{
    public function isConfigured(): bool;

    public function send(string $to, string $subject, string $body): bool;
}
