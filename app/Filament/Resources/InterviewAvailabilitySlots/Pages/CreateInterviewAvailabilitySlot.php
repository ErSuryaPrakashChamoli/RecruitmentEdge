<?php

namespace App\Filament\Resources\InterviewAvailabilitySlots\Pages;

use App\Filament\Resources\InterviewAvailabilitySlots\InterviewAvailabilitySlotResource;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\Employee;
use App\Models\Interviewer;
use App\Services\InterviewSchedulingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInterviewAvailabilitySlot extends CreateRecord
{
    protected static string $resource = InterviewAvailabilitySlotResource::class;

    protected static ?string $title = 'Publish interview availability';

    protected int $createdCount = 0;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $interviewer = Employee::query()->whereKey(array_keys(Interviewer::selectOptions()))->findOrFail($data['interviewer_id']);

        $slots = InterviewsTable::guarded('Slots could not be published', fn () => app(InterviewSchedulingService::class)->createSlots($interviewer, $data, auth()->user()?->employee));

        $this->createdCount = $slots->count();

        return $slots->first();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return "{$this->createdCount} slot(s) published";
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
