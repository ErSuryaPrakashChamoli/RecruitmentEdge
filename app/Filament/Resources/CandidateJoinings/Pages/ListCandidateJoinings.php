<?php

namespace App\Filament\Resources\CandidateJoinings\Pages;

use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCandidateJoinings extends ListRecords
{
    protected static string $resource = CandidateJoiningResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
