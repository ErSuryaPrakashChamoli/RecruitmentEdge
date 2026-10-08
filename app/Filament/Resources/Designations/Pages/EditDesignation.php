<?php

namespace App\Filament\Resources\Designations\Pages;

use App\Filament\Actions\MasterDataLifecycleActions;
use App\Filament\Resources\Designations\DesignationResource;
use Filament\Resources\Pages\EditRecord;

class EditDesignation extends EditRecord
{
    protected static string $resource = DesignationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...MasterDataLifecycleActions::all(),
        ];
    }
}
