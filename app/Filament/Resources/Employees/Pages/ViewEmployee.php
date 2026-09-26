<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\Actions\EmploymentActions;
use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EmploymentActions::deactivate(),
            EmploymentActions::reactivate(),
            EditAction::make(),
        ];
    }
}
