<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\JoiningStatus;
use App\Events\EmployeeConvertedFromCandidate;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Services\Identity\EmployeeLifecycleService;
use App\Services\Identity\HierarchyIntegrityService;
use App\Services\Identity\IdentityProvisioningService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Converts a joined candidate into an Employee record, preserving recruitment history via
 * `employees.candidate_id` (Section 44). This is the recruitment module's only hand-off point
 * into what will later become the broader HRMS employee lifecycle. Phase 8.3: needs
 * employees.convert within the actor's hierarchy, is idempotent and audited.
 *
 * Phase 8.4: conversion provisions the whole identity in one transaction — employee, manager
 * (hierarchy), sign-in access with the base role, and retirement of the candidate portal login —
 * all audited, events after commit. SaaS-2: sign-in access is an invitation (base role, linked to
 * the new employee record): the person accepts it with the identity that already uses their
 * address, or creates one — the conversion never reveals which. A candidate
 * whose earlier employment ended in a separation is rehired: the same employee record comes back
 * (history kept), never a duplicate. Needs employees.convert — users.manage is not a substitute.
 */
class EmployeeConversionService
{
    public function __construct(
        private readonly SequenceCodeGenerator $codeGenerator,
        private readonly IdentityProvisioningService $provisioning,
        private readonly HierarchyIntegrityService $hierarchy,
        private readonly EmployeeLifecycleService $lifecycle,
        private readonly CandidatePortalService $portal,
    ) {}

    /**
     * @param  int|null  $managerId  who the employee reports to; defaults to the requisition's
     *                               reporting, hiring or owning manager
     */
    public function convert(CandidateJoining $joining, ?User $actor = null, ?int $managerId = null): Employee
    {
        $actor ??= auth()->user();

        if (! $actor instanceof User || ! $actor->can('convert', $joining)) {
            throw new DomainException('Converting a candidate into an employee needs the employees.convert permission for this joining.');
        }

        if ($joining->status !== JoiningStatus::Joined) {
            throw new DomainException('Only a candidate marked as Joined can be converted to an employee.');
        }

        $candidate = $joining->candidateApplication->candidate;
        $requisition = $joining->candidateApplication->requisition;
        $offer = $joining->offer;
        $previous = Employee::withTrashed()->where('candidate_id', $candidate->id)->first();

        if ($previous !== null && $previous->status !== EmployeeStatus::Separated) {
            throw new DomainException('This candidate has already been converted to an employee.');
        }

        $managerId ??= $requisition?->reporting_manager_id ?? $requisition?->hiring_manager_id ?? $requisition?->manager_id;

        if ($managerId === null) {
            throw new DomainException('Choose the manager this employee will report to.');
        }

        $this->hierarchy->assertAssignableManager((int) $managerId, $actor);

        return DB::transaction(function () use ($joining, $candidate, $requisition, $offer, $actor, $managerId): Employee {
            // Idempotency: a concurrent second conversion waits here and then finds the employee
            // (employees.candidate_id is also unique).
            Candidate::query()->whereKey($candidate->id)->lockForUpdate()->first();
            $previous = Employee::withTrashed()->where('candidate_id', $candidate->id)->first();

            if ($previous !== null && $previous->status !== EmployeeStatus::Separated) {
                throw new DomainException('This candidate has already been converted to an employee.');
            }

            $placement = [
                'department_id' => $requisition->department_id,
                'designation_id' => $offer?->designation_id ?? $requisition->designation_id,
                'location_id' => $offer?->location_id ?? $requisition->location_id,
                'date_of_joining' => $joining->actual_doj ?? $joining->expected_doj,
            ];

            if ($previous !== null) {
                $employee = $this->lifecycle->rehire($previous, $actor, "Rehired through joining {$joining->id}");
                $employee->update($placement);
                $this->hierarchy->placeUnder($employee, (int) $managerId, $actor);
                $access = $this->provisioning->reactivateForRehire($employee, $actor);
            } else {
                [$firstName, $lastName] = $this->splitName($candidate->full_name);

                $employee = Employee::query()->create([
                    'candidate_id' => $candidate->id,
                    'employee_code' => $this->codeGenerator->next('EMP'),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $candidate->email,
                    'mobile' => $candidate->mobile,
                    'reports_to_id' => $managerId,
                    'status' => EmployeeStatus::Active,
                    ...$placement,
                ]);

                $access = $this->provisioning->inviteNewEmployee($employee, $actor, 'conversion');
            }

            AuditLog::record($joining, 'employee_converted', null, [
                'employee_id' => $employee->id,
                'candidate_id' => $candidate->id,
                'user_id' => $access instanceof User ? $access->id : null,
                'invitation_id' => $access instanceof TenantInvitation ? $access->id : null,
                'rehire' => $previous !== null,
                'by_user_id' => $actor->id,
            ]);

            // The candidate is now staff: their candidate portal login is retired (history kept).
            if (($account = $candidate->portalAccount) !== null && $account->is_active) {
                $this->portal->deactivate($account, $actor->employee);
            }

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
