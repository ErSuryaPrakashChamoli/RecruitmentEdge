<?php

namespace App\Filament\Resources\TalentPools\Pages;

use App\Filament\Resources\TalentPools\TalentPoolResource;
use App\Filament\Resources\TalentPools\Widgets\TalentPoolStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTalentPools extends ListRecords
{
    protected static string $resource = TalentPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TalentPoolStats::class,
        ];
    }
}
