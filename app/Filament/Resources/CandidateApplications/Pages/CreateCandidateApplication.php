<?php

namespace App\Filament\Resources\CandidateApplications\Pages;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\Candidates\Schemas\CandidatePicker;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\Employee;
use App\Services\HierarchyService;
use App\Services\SequenceCodeGenerator;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateCandidateApplication extends CreateRecord
{
    protected static string $resource = CandidateApplicationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        self::ensureRequisitionAcceptsApplications($data['requisition_id'] ?? null);
        self::ensureCandidateAndRecruiterInScope($data['candidate_id'] ?? null, $data['recruiter_id'] ?? null);

        $data['application_code'] = app(SequenceCodeGenerator::class)->next('APP');
        $data['current_stage'] = CandidateStage::Sourced;
        $data['status'] = ApplicationStatus::Active;

        return $data;
    }

    /**
     * Server-side guard (the select only lists Open requisitions, but a stale form or tampered
     * payload could still submit another one): rejects anything not Open and visible to the user.
     */
    public static function ensureRequisitionAcceptsApplications(mixed $requisitionId): void
    {
        if (filled($requisitionId) && RecruitmentRequisitionResource::applicationTargetQuery()->whereKey($requisitionId)->exists()) {
            return;
        }

        Notification::make()
            ->title('Application not created')
            ->body('Applications can only be created against an Open requisition. Choose an Open requisition and try again.')
            ->danger()
            ->send();

        throw new Halt;
    }

    /**
     * Phase 8.10 (P810-SEC-004): the server-side boundary behind the pickers. An application brings
     * its candidate into the recruiter's team's scope, so the candidate must already be visible to
     * the user and the recruiter must be the user or someone in their team (the same rule as
     * "Reassign recruiter"). A tampered payload is refused here even though the form never lists it.
     */
    public static function ensureCandidateAndRecruiterInScope(mixed $candidateId, mixed $recruiterId): void
    {
        $recruiter = filled($recruiterId) ? Employee::query()->find($recruiterId) : null;

        if (filled($candidateId)
            && CandidatePicker::selectableCandidates()->whereKey($candidateId)->exists()
            && $recruiter !== null
            && app(HierarchyService::class)->canView(Filament::auth()->user(), $recruiter)) {
            return;
        }

        Notification::make()
            ->title('Application not created')
            ->body('Choose a candidate you can see and a recruiter from your team, then try again.')
            ->danger()
            ->send();

        throw new Halt;
    }
}
