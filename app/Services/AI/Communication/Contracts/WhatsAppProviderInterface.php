<?php

namespace App\Services\AI\Communication\Contracts;

interface WhatsAppProviderInterface
{
    public function isConfigured(): bool;

    public function send(string $toPhoneNumber, string $message): bool;
}
