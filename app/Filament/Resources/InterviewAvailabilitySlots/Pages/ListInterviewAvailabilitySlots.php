<?php

namespace App\Filament\Resources\InterviewAvailabilitySlots\Pages;

use App\Filament\Resources\InterviewAvailabilitySlots\InterviewAvailabilitySlotResource;
use App\Filament\Resources\InterviewAvailabilitySlots\Widgets\SelfSchedulingStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInterviewAvailabilitySlots extends ListRecords
{
    protected static string $resource = InterviewAvailabilitySlotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Publish availability'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            SelfSchedulingStats::class,
        ];
    }
}
