<?php

namespace App\Filament\Resources\OfferLetterTemplates\Pages;

use App\Filament\Resources\OfferLetterTemplates\Actions\OfferLetterTemplateActions;
use App\Filament\Resources\OfferLetterTemplates\OfferLetterTemplateResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOfferLetterTemplate extends ViewRecord
{
    protected static string $resource = OfferLetterTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OfferLetterTemplateActions::downloadWordFile(),
            OfferLetterTemplateActions::uploadWordFile(),
            OfferLetterTemplateActions::restoreOriginal(),
            EditAction::make(),
        ];
    }
}
