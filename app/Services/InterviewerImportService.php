<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Interviewer;
use DomainException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Adds employees to the interviewer list from an uploaded Excel/CSV sheet. Rows are matched to
 * employees by the "Emp ID" column (employees.employee_code); Name and Designation are there for the
 * administrator's readability — the employee record stays the source of truth for both.
 */
class InterviewerImportService
{
    /**
     * @var array<int, string>
     */
    public const array HEADINGS = ['Emp ID', 'Name', 'Designation'];

    /**
     * @return array{added: int, already_listed: int, skipped: array<int, string>}
     */
    public function import(string $absolutePath): array
    {
        try {
            $rows = IOFactory::load($absolutePath)->getActiveSheet()->toArray(null, true, true, false);
        } catch (ReaderException) {
            throw new DomainException('The file could not be read. Upload an Excel (.xlsx, .xls) or CSV file.');
        }

        $headings = array_map(
            fn (mixed $heading): string => (string) preg_replace('/[^a-z]/', '', strtolower((string) $heading)),
            array_shift($rows) ?? [],
        );
        $empIdColumn = collect(['empid', 'employeeid', 'employeecode'])
            ->map(fn (string $heading): int|false => array_search($heading, $headings, true))
            ->first(fn (int|false $column): bool => $column !== false);

        if ($empIdColumn === null) {
            throw new DomainException('The sheet must have an "Emp ID" column in the first row.');
        }

        $result = ['added' => 0, 'already_listed' => 0, 'skipped' => []];

        foreach ($rows as $index => $row) {
            $empId = trim((string) ($row[$empIdColumn] ?? ''));

            if ($empId === '') {
                continue;
            }

            $employee = Employee::query()->where('employee_code', $empId)->first();

            if ($employee === null) {
                $result['skipped'][] = 'Row '.($index + 2).": no employee with Emp ID {$empId}";

                continue;
            }

            $interviewer = Interviewer::query()->firstOrNew(['employee_id' => $employee->id]);

            if ($interviewer->exists && $interviewer->is_active) {
                $result['already_listed']++;

                continue;
            }

            $interviewer->is_active = true;
            $interviewer->save();
            $result['added']++;
        }

        return $result;
    }

    /**
     * Streams a blank sheet with the expected headings to the output buffer.
     */
    public function writeTemplate(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Interviewers')->fromArray(self::HEADINGS);

        (new Xlsx($spreadsheet))->save('php://output');
    }
}
