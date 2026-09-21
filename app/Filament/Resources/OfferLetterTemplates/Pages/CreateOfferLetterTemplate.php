<?php

namespace App\Filament\Resources\OfferLetterTemplates\Pages;

use App\Filament\Resources\OfferLetterTemplates\Actions\OfferLetterTemplateActions;
use App\Filament\Resources\OfferLetterTemplates\OfferLetterTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOfferLetterTemplate extends CreateRecord
{
    protected static string $resource = OfferLetterTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (filled($data['file_path'] ?? null)) {
            OfferLetterTemplateActions::guardPlaceholders($data['file_path']);
        }

        $data['created_by'] = auth()->user()?->employee_id;

        return $data;
    }
}
