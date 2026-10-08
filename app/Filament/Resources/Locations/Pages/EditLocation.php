<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Actions\MasterDataLifecycleActions;
use App\Filament\Resources\Locations\LocationResource;
use Filament\Resources\Pages\EditRecord;

class EditLocation extends EditRecord
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...MasterDataLifecycleActions::all(),
        ];
    }
}
