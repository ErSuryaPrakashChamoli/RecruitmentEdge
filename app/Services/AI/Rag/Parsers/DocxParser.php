<?php

namespace App\Services\AI\Rag\Parsers;

use App\Services\AI\Contracts\DocumentParserInterface;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;

/**
 * Word documents: .docx via PhpWord's Word2007 reader, legacy binary .doc via its MsDoc reader
 * (text extraction from .doc is best-effort — complex layouts may lose structure, but plain body
 * text comes through, which is all RAG chunking needs).
 */
class DocxParser implements DocumentParserInterface
{
    public function supports(string $mimeType, string $extension): bool
    {
        return in_array($extension, ['docx', 'doc'], true)
            || in_array($mimeType, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword'], true);
    }

    public function extractText(string $absolutePath): string
    {
        $reader = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === 'doc' ? 'MsDoc' : 'Word2007';
        $document = IOFactory::load($absolutePath, $reader);
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
