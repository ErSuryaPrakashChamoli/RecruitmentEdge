<?php

namespace App\Services\AI\Rag\Parsers;

use App\Services\AI\Contracts\DocumentParserInterface;
use Smalot\PdfParser\Parser;

class PdfParser implements DocumentParserInterface
{
    public function supports(string $mimeType, string $extension): bool
    {
        return $extension === 'pdf' || $mimeType === 'application/pdf';
    }

    public function extractText(string $absolutePath): string
    {
        return (new Parser)->parseFile($absolutePath)->getText();
    }
}
