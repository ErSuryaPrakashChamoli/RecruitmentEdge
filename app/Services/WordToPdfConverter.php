<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use Throwable;

/**
 * Converts a filled Word offer letter to the PDF candidates receive. Headless LibreOffice keeps the
 * Word layout exactly; when it is not installed or fails, PhpWord's own DomPDF writer is used so the
 * candidate still gets a PDF, with simpler formatting.
 */
class WordToPdfConverter
{
    public function convert(string $docxPath): string
    {
        return $this->convertWithLibreOffice($docxPath) ?? $this->convertWithPhpWord($docxPath);
    }

    public function convertWithLibreOffice(string $docxPath): ?string
    {
        $outputDirectory = sys_get_temp_dir().'/offer-letter-pdf-'.Str::uuid();
        File::ensureDirectoryExists($outputDirectory);

        try {
            $result = Process::timeout((int) config('services.libreoffice.timeout'))->run([
                (string) config('services.libreoffice.binary'),
                '-env:UserInstallation=file://'.$outputDirectory.'/profile',
                '--headless',
                '--convert-to',
                'pdf',
                '--outdir',
                $outputDirectory,
                $docxPath,
            ]);

            $pdfPath = $outputDirectory.'/'.pathinfo($docxPath, PATHINFO_FILENAME).'.pdf';

            if (! $result->successful() || ! is_file($pdfPath)) {
                Log::warning('LibreOffice could not convert an offer letter to PDF; using the PhpWord fallback.', [
                    'error' => $result->errorOutput(),
                ]);

                return null;
            }

            return (string) file_get_contents($pdfPath);
        } catch (Throwable $exception) {
            Log::warning('LibreOffice is unavailable for offer letter PDFs; using the PhpWord fallback.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        } finally {
            File::deleteDirectory($outputDirectory);
        }
    }

    public function convertWithPhpWord(string $docxPath): string
    {
        Settings::setPdfRendererName(Settings::PDF_RENDERER_DOMPDF);
        Settings::setPdfRendererPath(base_path('vendor/dompdf/dompdf'));

        $pdfPath = sys_get_temp_dir().'/offer-letter-'.Str::uuid().'.pdf';

        try {
            IOFactory::createWriter(IOFactory::load($docxPath), 'PDF')->save($pdfPath);

            return (string) file_get_contents($pdfPath);
        } finally {
            File::delete($pdfPath);
        }
    }
}
