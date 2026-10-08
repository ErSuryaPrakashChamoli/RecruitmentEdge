<?php

namespace App\Filament\Resources\EmployeeSeparations\Pages;

use App\Filament\Resources\EmployeeSeparations\EmployeeSeparationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeSeparations extends ListRecords
{
    protected static string $resource = EmployeeSeparationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Record separation'),
        ];
    }
}
