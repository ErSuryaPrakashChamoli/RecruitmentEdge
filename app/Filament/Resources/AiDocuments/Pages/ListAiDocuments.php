<?php

namespace App\Filament\Resources\AiDocuments\Pages;

use App\Filament\Resources\AiDocuments\AiDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiDocuments extends ListRecords
{
    protected static string $resource = AiDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
