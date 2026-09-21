<?php

namespace App\Filament\Resources\OfferLetterTemplates\Pages;

use App\Filament\Resources\OfferLetterTemplates\Actions\OfferLetterTemplateActions;
use App\Filament\Resources\OfferLetterTemplates\OfferLetterTemplateResource;
use App\Models\OfferLetterTemplate;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOfferLetterTemplate extends EditRecord
{
    protected static string $resource = OfferLetterTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OfferLetterTemplateActions::downloadWordFile(),
            OfferLetterTemplateActions::uploadWordFile(),
            OfferLetterTemplateActions::restoreOriginal(),
            DeleteAction::make()
                ->hidden(fn (OfferLetterTemplate $record): bool => $record->is_system),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (filled($data['file_path'] ?? null) && $data['file_path'] !== $this->getRecord()->getAttribute('file_path')) {
            OfferLetterTemplateActions::guardPlaceholders($data['file_path']);
        }

        return $data;
    }
}
