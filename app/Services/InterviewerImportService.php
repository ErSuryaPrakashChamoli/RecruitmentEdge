<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Interviewer;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
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

    private const int CHUNK = 500;

    /**
     * Phase 8.6 (D8.6-009): the whole sheet is applied in one transaction (all rows or none),
     * employees are looked up in chunks, and an interviewer an administrator deactivated is
     * reported — never silently reactivated. The import is audited as one summary row.
     *
     * @return array{added: int, already_listed: int, inactive_not_reactivated: array<int, string>, skipped: array<int, string>}
     */
    public function import(string $absolutePath, ?User $actor = null): array
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

        $codes = collect($rows)
            ->map(fn (array $row, int $index): array => ['row' => $index + 2, 'code' => trim((string) ($row[$empIdColumn] ?? ''))])
            ->filter(fn (array $row): bool => $row['code'] !== '')
            ->values();

        $result = ['added' => 0, 'already_listed' => 0, 'inactive_not_reactivated' => [], 'skipped' => []];

        DB::transaction(function () use ($codes, &$result, $actor): void {
            foreach ($codes->chunk(self::CHUNK) as $chunk) {
                $employees = Employee::query()->whereIn('employee_code', $chunk->pluck('code'))->get()->keyBy('employee_code');
                $listed = Interviewer::query()->whereIn('employee_id', $employees->pluck('id'))->get()->keyBy('employee_id');

                foreach ($chunk as ['row' => $row, 'code' => $code]) {
                    $employee = $employees->get($code);

                    if ($employee === null) {
                        $result['skipped'][] = "Row {$row}: no employee with Emp ID {$code}";

                        continue;
                    }

                    $interviewer = $listed->get($employee->id);

                    if ($interviewer !== null && $interviewer->is_active) {
                        $result['already_listed']++;

                        continue;
                    }

                    if ($interviewer !== null) {
                        $result['inactive_not_reactivated'][] = "Row {$row}: {$code} is deactivated — activate them from the list if they should interview again";

                        continue;
                    }

                    $listed->put($employee->id, Interviewer::query()->create(['employee_id' => $employee->id, 'is_active' => true]));
                    $result['added']++;
                }
            }

            if ($actor !== null) {
                AuditLog::record($actor, 'interviewers_imported', null, [
                    'added' => $result['added'],
                    'already_listed' => $result['already_listed'],
                    'inactive_not_reactivated' => count($result['inactive_not_reactivated']),
                    'skipped' => count($result['skipped']),
                ]);
            }
        });

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
