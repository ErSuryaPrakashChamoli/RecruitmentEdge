<?php

use App\Filament\Exports\CandidateExporter;
use App\Models\Candidate;
use App\Services\Export\ReportExportService;
use Filament\Actions\Exports\Models\Export;

/**
 * SEC-88-12 (owner decision 2026-10-01, A): a value a spreadsheet would run as a formula — such as
 * a name typed into the public career form — is written as text in every table export and report
 * CSV. Signed numbers stay numbers.
 */
test('a table export writes a formula-like candidate name as text', function (): void {
    $candidate = Candidate::factory()->create([
        'full_name' => '=HYPERLINK("http://evil.example","Click me")',
        'mobile' => '+919811122233',
    ]);
    $export = new Export(['exporter' => CandidateExporter::class]);

    $row = (new CandidateExporter($export, ['full_name' => 'Full name', 'mobile' => 'Mobile'], []))($candidate);

    expect($row[0])->toBe('\'=HYPERLINK("http://evil.example","Click me")')
        ->and($row[1])->toBe('+919811122233');
});

test('a report CSV writes formula-like cells as text and keeps plain values and signed numbers', function (): void {
    $response = app(ReportExportService::class)->streamCsv('report.csv', ['Source', 'Change'], [
        ['=cmd|\' /C calc\'!A0', '-5'],
        ['@SUM(A1:A9)', '+12'],
        ['-2+3', '0'],
        ['LinkedIn', '7'],
    ]);

    ob_start();
    $response->sendContent();
    $lines = array_map(str_getcsv(...), array_filter(explode("\n", (string) ob_get_clean())));

    expect($lines)->toBe([
        ['Source', 'Change'],
        ['\'=cmd|\' /C calc\'!A0', '-5'],
        ['\'@SUM(A1:A9)', '+12'],
        ['\'-2+3', '0'],
        ['LinkedIn', '7'],
    ]);
});
