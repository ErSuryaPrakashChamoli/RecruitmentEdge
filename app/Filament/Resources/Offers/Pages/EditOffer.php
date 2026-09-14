<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\Offers\Tables\OffersTable;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOffer extends EditRecord
{
    protected static string $resource = OfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OffersTable::releaseAction(),
            OffersTable::downloadOfferLetterAction(),
            DeleteAction::make(),
        ];
    }
}
