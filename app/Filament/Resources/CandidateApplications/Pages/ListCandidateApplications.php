<?php

namespace App\Filament\Resources\CandidateApplications\Pages;

use App\Filament\Concerns\HasSavedTableViews;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCandidateApplications extends ListRecords
{
    use HasSavedTableViews;

    protected static string $resource = CandidateApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedTableViewActions(),
            CreateAction::make(),
        ];
    }
}
