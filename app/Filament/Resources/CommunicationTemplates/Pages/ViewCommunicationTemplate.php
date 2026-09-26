<?php

namespace App\Filament\Resources\CommunicationTemplates\Pages;

use App\Filament\Resources\CommunicationTemplates\CommunicationTemplateResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCommunicationTemplate extends ViewRecord
{
    protected static string $resource = CommunicationTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
