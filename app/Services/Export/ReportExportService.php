<?php

namespace App\Services\Export;

use App\Enums\Entitlement;
use App\Services\Entitlements\EntitlementService;
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
        // SaaS-3: a bulk data export needs data exports in the tenant's plan, whoever asks for it.
        app(EntitlementService::class)->require(Entitlement::ExportsData);

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map(self::neutraliseFormula(...), $headers));

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::neutraliseFormula(...), $row));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Phase 8.8 (SEC-88-12): a text cell a spreadsheet would run as a formula (leading =, +, -, @,
     * tab or carriage return) is written with a leading apostrophe — the same rule Filament applies
     * to table exports. Signed numbers such as "-5" stay numbers.
     */
    public static function neutraliseFormula(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (in_array($value[0], ['-', '+'], true) && is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
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
