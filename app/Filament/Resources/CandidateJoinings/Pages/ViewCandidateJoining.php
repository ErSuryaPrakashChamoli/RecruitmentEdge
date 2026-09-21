<?php

namespace App\Filament\Resources\CandidateJoinings\Pages;

use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCandidateJoining extends ViewRecord
{
    protected static string $resource = CandidateJoiningResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
