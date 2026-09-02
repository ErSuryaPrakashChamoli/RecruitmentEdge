<?php

namespace App\Services\AI\Contracts;

interface DocumentParserInterface
{
    public function supports(string $mimeType, string $extension): bool;

    public function extractText(string $absolutePath): string;
}
