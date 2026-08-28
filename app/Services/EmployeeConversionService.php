<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\JoiningStatus;
use App\Models\CandidateJoining;
use App\Models\Employee;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Converts a joined candidate into an Employee record, preserving recruitment history via
 * `employees.candidate_id` (Section 44). This is the recruitment module's only hand-off point
 * into what will later become the broader HRMS employee lifecycle.
 */
class EmployeeConversionService
{
    public function __construct(private readonly SequenceCodeGenerator $codeGenerator) {}

    public function convert(CandidateJoining $joining): Employee
    {
        if ($joining->status !== JoiningStatus::Joined) {
            throw new DomainException('Only a candidate marked as Joined can be converted to an employee.');
        }

        $candidate = $joining->candidateApplication->candidate;

        if ($candidate->employee !== null) {
            throw new DomainException('This candidate has already been converted to an employee.');
        }

        $requisition = $joining->candidateApplication->requisition;
        $offer = $joining->offer;

        return DB::transaction(function () use ($joining, $candidate, $requisition, $offer): Employee {
            [$firstName, $lastName] = $this->splitName($candidate->full_name);

            return Employee::query()->create([
                'candidate_id' => $candidate->id,
                'employee_code' => $this->codeGenerator->next('EMP'),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $candidate->email,
                'mobile' => $candidate->mobile,
                'department_id' => $requisition->department_id,
                'designation_id' => $offer?->designation_id ?? $requisition->designation_id,
                'location_id' => $offer?->location_id ?? $requisition->location_id,
                'date_of_joining' => $joining->actual_doj ?? $joining->expected_doj,
                'status' => EmployeeStatus::Active,
            ]);
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
