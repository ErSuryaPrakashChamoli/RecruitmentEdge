<?php

namespace App\Filament\Resources\Interviews\Pages;

use App\Filament\Resources\Interviews\InterviewResource;
use App\Filament\Resources\Interviews\Schemas\InterviewForm;
use App\Models\CandidateApplication;
use App\Services\InterviewService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Creation is delegated to InterviewService::schedule() (not a plain Eloquent create) so the
 * stage sync and interviewer/recruiter notifications happen identically on every scheduling path.
 */
class CreateInterview extends CreateRecord
{
    protected static string $resource = InterviewResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $application = InterviewForm::scopeApplicationsToViewer(CandidateApplication::query())
            ->findOrFail($data['candidate_application_id']);

        try {
            return app(InterviewService::class)->schedule($application, $data, auth()->user()?->employee);
        } catch (DomainException $e) {
            Notification::make()
                ->title('Interview could not be scheduled')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }
}
