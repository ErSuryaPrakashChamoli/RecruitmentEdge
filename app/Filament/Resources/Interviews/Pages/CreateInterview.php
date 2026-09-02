<?php

namespace App\Filament\Resources\Interviews\Pages;

use App\Enums\InterviewStatus;
use App\Filament\Resources\Interviews\InterviewResource;
use App\Models\Interview;
use App\Services\NotificationDispatchService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateInterview extends CreateRecord
{
    protected static string $resource = InterviewResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = InterviewStatus::Scheduled;
        $data['created_by'] = Filament::auth()->user()?->employee_id;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Interview $interview */
        $interview = $this->record;

        app(NotificationDispatchService::class)->alert(
            $interview->interviewer?->user,
            'Interviews',
            'Interview scheduled',
            "You've been scheduled to interview {$interview->candidateApplication->candidate->full_name} on {$interview->scheduled_at->format('d M Y, h:i A')}.",
            'info',
            InterviewResource::getUrl('edit', ['record' => $interview]),
        );
    }
}
