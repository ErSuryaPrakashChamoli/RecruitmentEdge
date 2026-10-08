<?php

namespace App\Filament\Resources\TalentPools\Pages;

use App\Filament\Resources\TalentPools\Tables\TalentPoolsTable;
use App\Filament\Resources\TalentPools\TalentPoolResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTalentPool extends ViewRecord
{
    protected static string $resource = TalentPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            TalentPoolsTable::archiveAction(),
        ];
    }
}
