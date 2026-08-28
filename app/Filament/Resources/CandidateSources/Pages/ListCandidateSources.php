<?php

namespace App\Filament\Resources\CandidateSources\Pages;

use App\Filament\Resources\CandidateSources\CandidateSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCandidateSources extends ListRecords
{
    protected static string $resource = CandidateSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
