<?php

namespace App\Filament\Resources\CandidateJoinings\Pages;

use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCandidateJoining extends EditRecord
{
    protected static string $resource = CandidateJoiningResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
