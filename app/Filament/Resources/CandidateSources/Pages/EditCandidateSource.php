<?php

namespace App\Filament\Resources\CandidateSources\Pages;

use App\Filament\Actions\MasterDataLifecycleActions;
use App\Filament\Resources\CandidateSources\CandidateSourceResource;
use Filament\Resources\Pages\EditRecord;

class EditCandidateSource extends EditRecord
{
    protected static string $resource = CandidateSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...MasterDataLifecycleActions::all(),
        ];
    }
}
