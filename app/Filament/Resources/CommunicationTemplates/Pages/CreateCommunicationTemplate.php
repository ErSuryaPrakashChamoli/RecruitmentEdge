<?php

namespace App\Filament\Resources\CommunicationTemplates\Pages;

use App\Filament\Resources\CommunicationTemplates\CommunicationTemplateResource;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Services\Communication\CommunicationTemplateService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCommunicationTemplate extends CreateRecord
{
    protected static string $resource = CommunicationTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return InterviewsTable::guarded('Template could not be saved', fn () => app(CommunicationTemplateService::class)->create($data, auth()->user()?->employee));
    }
}
