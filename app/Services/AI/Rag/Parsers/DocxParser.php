<?php

namespace App\Services\AI\Rag\Parsers;

use App\Services\AI\Contracts\DocumentParserInterface;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;

class DocxParser implements DocumentParserInterface
{
    public function supports(string $mimeType, string $extension): bool
    {
        return $extension === 'docx'
            || $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    public function extractText(string $absolutePath): string
    {
        $document = IOFactory::load($absolutePath);
        $lines = [];

        foreach ($document->getSections() as $section) {
            $this->collectText($section, $lines);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function collectText(AbstractContainer $container, array &$lines): void
    {
        foreach ($container->getElements() as $element) {
            if ($element instanceof Text) {
                $lines[] = $element->getText();
            } elseif ($element instanceof TextRun) {
                $lines[] = $element->getText();
            } elseif ($element instanceof AbstractContainer) {
                $this->collectText($element, $lines);
            }
        }
    }
}
