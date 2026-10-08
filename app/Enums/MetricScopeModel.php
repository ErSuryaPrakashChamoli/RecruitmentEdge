<?php

namespace App\Enums;

/**
 * The one hierarchy scope a governed metric uses (Phase 8.5 "Scope" decision). Application-level
 * metrics follow the application's owner; requisition-level metrics follow involvement in the
 * requisition (RecruitmentRequisition::scopeVisibleTo).
 */
enum MetricScopeModel: string
{
    case ApplicationOwner = 'application_owner';
    case RequisitionInvolvement = 'requisition_involvement';
    case Actor = 'actor';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::ApplicationOwner => 'Applications owned by the viewer\'s team (current owner)',
            self::RequisitionInvolvement => 'Requisitions the viewer\'s team is involved in',
            self::Actor => 'Events performed by the viewer\'s team',
            self::Organization => 'Whole organization (only for viewers who see everything)',
        };
    }
}
