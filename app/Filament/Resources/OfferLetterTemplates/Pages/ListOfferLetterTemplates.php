<?php

namespace App\Filament\Resources\OfferLetterTemplates\Pages;

use App\Filament\Resources\OfferLetterTemplates\OfferLetterTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOfferLetterTemplates extends ListRecords
{
    protected static string $resource = OfferLetterTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
