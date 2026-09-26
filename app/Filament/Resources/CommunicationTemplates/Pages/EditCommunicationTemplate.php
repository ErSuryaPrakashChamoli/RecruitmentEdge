<?php

namespace App\Filament\Resources\CommunicationTemplates\Pages;

use App\Filament\Resources\CommunicationTemplates\CommunicationTemplateResource;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\CommunicationTemplate;
use App\Services\Communication\CommunicationTemplateService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCommunicationTemplate extends EditRecord
{
    protected static string $resource = CommunicationTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var CommunicationTemplate $record */
        return InterviewsTable::guarded('Template could not be saved', fn () => app(CommunicationTemplateService::class)->update($record, $data, auth()->user()?->employee));
    }
}
