<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Filament\Concerns\HasSavedTableViews;
use App\Filament\Resources\Candidates\CandidateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCandidates extends ListRecords
{
    use HasSavedTableViews;

    protected static string $resource = CandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedTableViewActions(),
            CreateAction::make(),
        ];
    }
}
