<?php

namespace App\Filament\Resources\CandidateSources\Pages;

use App\Filament\Resources\CandidateSources\CandidateSourceResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCandidateSource extends EditRecord
{
    protected static string $resource = CandidateSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
