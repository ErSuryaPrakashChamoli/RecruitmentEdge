<?php

namespace App\Filament\Resources\InterviewAvailabilitySlots\Pages;

use App\Filament\Resources\InterviewAvailabilitySlots\InterviewAvailabilitySlotResource;
use App\Filament\Resources\InterviewAvailabilitySlots\Tables\InterviewAvailabilitySlotsTable;
use Filament\Resources\Pages\ViewRecord;

class ViewInterviewAvailabilitySlot extends ViewRecord
{
    protected static string $resource = InterviewAvailabilitySlotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InterviewAvailabilitySlotsTable::cancelSlotAction(),
        ];
    }
}
