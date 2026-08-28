<?php

namespace App\Filament\Resources\RecruiterIncentivePayments\Pages;

use App\Filament\Resources\RecruiterIncentivePayments\RecruiterIncentivePaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruiterIncentivePayments extends ListRecords
{
    protected static string $resource = RecruiterIncentivePaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
