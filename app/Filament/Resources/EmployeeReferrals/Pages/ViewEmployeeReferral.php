<?php

namespace App\Filament\Resources\EmployeeReferrals\Pages;

use App\Filament\Resources\EmployeeReferrals\Actions\ReferralActions;
use App\Filament\Resources\EmployeeReferrals\EmployeeReferralResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployeeReferral extends ViewRecord
{
    protected static string $resource = EmployeeReferralResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...ReferralActions::all(),
            EditAction::make(),
        ];
    }
}
