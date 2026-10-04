<?php

namespace App\Services;

use App\Enums\Entitlement;
use App\Enums\RequisitionStatus;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use Closure;
use DomainException;

/**
 * SaaS-3: the way a requisition comes into being (or back from the bin) — the two operations that
 * add an active requisition. Both need the person's permission AND the tenant's plan to allow one
 * more active requisition; neither stands in for the other. The limit is taken atomically
 * (EntitlementService::consume — two people can never both create the last allowed one).
 *
 * Status changes stay with RequisitionApprovalService: closed and cancelled are terminal, so a
 * status change never adds an active requisition.
 */
class RequisitionService
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * @template T of RecruitmentRequisition
     *
     * @param  Closure(): T  $create  builds and saves the record (the Filament form's own creation)
     * @return T
     */
    public function create(User $actor, Closure $create): RecruitmentRequisition
    {
        if (! $actor->can('create', RecruitmentRequisition::class)) {
            throw new DomainException('Creating requisitions needs the requisitions.create permission.');
        }

        return $this->entitlements->consume(Entitlement::RequisitionsActiveMax, $create);
    }

    public function restore(RecruitmentRequisition $requisition, User $actor): RecruitmentRequisition
    {
        if (! $actor->can('restore', $requisition)) {
            throw new DomainException('You cannot restore this requisition.');
        }

        // A closed or cancelled requisition is not active: restoring it takes nothing from the plan.
        if (in_array($requisition->status, [RequisitionStatus::Closed, RequisitionStatus::Cancelled], true)) {
            $requisition->restore();

            return $requisition;
        }

        return $this->entitlements->consume(Entitlement::RequisitionsActiveMax, function () use ($requisition): RecruitmentRequisition {
            $requisition->restore();

            return $requisition;
        });
    }
}
