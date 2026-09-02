<?php

namespace App\Services\AI\Rag\Parsers;

use App\Services\AI\Contracts\DocumentParserInterface;

class PlainTextParser implements DocumentParserInterface
{
    public function supports(string $mimeType, string $extension): bool
    {
        return in_array($extension, ['txt', 'md', 'markdown'], true) || str_starts_with($mimeType, 'text/');
    }

    public function extractText(string $absolutePath): string
    {
        return (string) file_get_contents($absolutePath);
    }
}
