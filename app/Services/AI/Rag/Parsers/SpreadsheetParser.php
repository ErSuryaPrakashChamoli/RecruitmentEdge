<?php

namespace App\Services\AI\Rag\Parsers;

use App\Services\AI\Contracts\DocumentParserInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SpreadsheetParser implements DocumentParserInterface
{
    public function supports(string $mimeType, string $extension): bool
    {
        return in_array($extension, ['xlsx', 'xls', 'csv'], true);
    }

    public function extractText(string $absolutePath): string
    {
        $spreadsheet = IOFactory::load($absolutePath);
        $lines = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $lines[] = "# {$sheet->getTitle()}";

            foreach ($sheet->toArray(null, true, true, false) as $row) {
                $lines[] = implode(' | ', array_map(fn ($cell) => (string) $cell, $row));
            }
        }

        return implode("\n", $lines);
    }
}
