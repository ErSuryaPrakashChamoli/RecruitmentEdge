<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\JoiningStatus;
use App\Events\EmployeeConvertedFromCandidate;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Converts a joined candidate into an Employee record, preserving recruitment history via
 * `employees.candidate_id` (Section 44). This is the recruitment module's only hand-off point
 * into what will later become the broader HRMS employee lifecycle. Phase 8.3: needs
 * employees.convert within the actor's hierarchy, is idempotent and audited.
 */
class EmployeeConversionService
{
    public function __construct(private readonly SequenceCodeGenerator $codeGenerator) {}

    public function convert(CandidateJoining $joining, ?User $actor = null): Employee
    {
        $actor ??= auth()->user();

        if (! $actor instanceof User || ! $actor->can('convert', $joining)) {
            throw new DomainException('Converting a candidate into an employee needs the employees.convert permission for this joining.');
        }

        if ($joining->status !== JoiningStatus::Joined) {
            throw new DomainException('Only a candidate marked as Joined can be converted to an employee.');
        }

        $candidate = $joining->candidateApplication->candidate;

        if ($candidate->employee !== null) {
            throw new DomainException('This candidate has already been converted to an employee.');
        }

        $requisition = $joining->candidateApplication->requisition;
        $offer = $joining->offer;

        return DB::transaction(function () use ($joining, $candidate, $requisition, $offer, $actor): Employee {
            // Idempotency: a concurrent second conversion waits here and then finds the employee
            // (employees.candidate_id is also unique).
            Candidate::query()->whereKey($candidate->id)->lockForUpdate()->first();

            if (Employee::query()->where('candidate_id', $candidate->id)->exists()) {
                throw new DomainException('This candidate has already been converted to an employee.');
            }

            [$firstName, $lastName] = $this->splitName($candidate->full_name);

            $employee = Employee::query()->create([
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

            AuditLog::record($joining, 'employee_converted', null, ['employee_id' => $employee->id, 'candidate_id' => $candidate->id, 'by_user_id' => $actor->id]);

            // Phase 8.2: links the employee to the Outcome Loop hiring snapshot (ids only, after commit).
            EmployeeConvertedFromCandidate::dispatch($candidate->id, $joining->candidate_application_id, $requisition?->id, $employee->id, now()->toIso8601String());

            return $employee;
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
