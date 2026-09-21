<?php

namespace App\Filament\Resources\CandidateSources\Pages;

use App\Filament\Resources\CandidateSources\CandidateSourceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCandidateSource extends ViewRecord
{
    protected static string $resource = CandidateSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
