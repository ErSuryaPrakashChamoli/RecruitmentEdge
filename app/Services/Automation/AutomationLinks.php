<?php

namespace App\Services\Automation;

use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\CandidateCommunications\CandidateCommunicationResource;
use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use App\Filament\Resources\EmployeeReferrals\EmployeeReferralResource;
use App\Filament\Resources\HiringRisks\HiringRiskResource;
use App\Filament\Resources\Interviews\InterviewResource;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\CandidateJoining;
use App\Models\EmployeeReferral;
use App\Models\HiringRisk;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentRequisition;
use Illuminate\Database\Eloquent\Model;

/**
 * The "take action" link for an automation subject: the existing resource page that already
 * manages that record (and applies its own authorization).
 */
final class AutomationLinks
{
    public static function for(?Model $subject): ?string
    {
        return match (true) {
            $subject instanceof CandidateApplication => CandidateApplicationResource::getUrl('view', ['record' => $subject]),
            $subject instanceof Interview => InterviewResource::getUrl('view', ['record' => $subject]),
            $subject instanceof Offer => OfferResource::getUrl('view', ['record' => $subject]),
            $subject instanceof CandidateJoining => CandidateJoiningResource::getUrl('view', ['record' => $subject]),
            $subject instanceof RecruitmentRequisition => RecruitmentRequisitionResource::getUrl('view', ['record' => $subject]),
            $subject instanceof EmployeeReferral => EmployeeReferralResource::getUrl('view', ['record' => $subject]),
            $subject instanceof CandidateCommunication => CandidateCommunicationResource::getUrl('view', ['record' => $subject]),
            $subject instanceof HiringRisk => $subject->requisition_id !== null
                ? RecruitmentRequisitionResource::getUrl('intelligence', ['record' => $subject->requisition_id])
                : HiringRiskResource::getUrl('index'),
            default => null,
        };
    }
}
