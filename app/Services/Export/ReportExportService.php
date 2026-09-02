<?php

namespace App\Services\Export;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reusable CSV/PDF writers for the small, already-computed report sections on RecruitmentReports
 * (funnel, source ROI, vacancy ageing) — deliberately separate from Filament's native ExportAction,
 * which already covers CSV/XLSX for the large Eloquent-backed resource tables (Section 37: do not
 * write isolated export code for every report).
 */
class ReportExportService
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public function streamCsv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Built as a StreamedResponse (not dompdf's own Response) because Livewire's file-download
     * mechanism only recognizes StreamedResponse/BinaryFileResponse — a plain Response falls
     * through untouched and its binary content then fails Livewire's UTF-8 JSON payload encoding.
     *
     * @param  array<string, mixed>  $data
     */
    public function streamPdf(string $filename, string $view, array $data): StreamedResponse
    {
        $binary = Pdf::loadView($view, $data)->output();

        return response()->streamDownload(function () use ($binary): void {
            echo $binary;
        }, $filename, ['Content-Type' => 'application/pdf']);
    }
}
