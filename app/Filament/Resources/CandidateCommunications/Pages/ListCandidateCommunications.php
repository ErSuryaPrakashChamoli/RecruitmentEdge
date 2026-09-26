<?php

namespace App\Filament\Resources\CandidateCommunications\Pages;

use App\Filament\Resources\CandidateCommunications\Actions\SendMessageAction;
use App\Filament\Resources\CandidateCommunications\CandidateCommunicationResource;
use App\Filament\Resources\CandidateCommunications\Widgets\CommunicationStats;
use Filament\Resources\Pages\ListRecords;

class ListCandidateCommunications extends ListRecords
{
    protected static string $resource = CandidateCommunicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SendMessageAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CommunicationStats::class,
        ];
    }
}
