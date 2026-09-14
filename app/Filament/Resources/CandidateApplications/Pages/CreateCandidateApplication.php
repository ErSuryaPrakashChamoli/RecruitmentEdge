<?php

namespace App\Filament\Resources\CandidateApplications\Pages;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Services\SequenceCodeGenerator;
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
}
