<?php

namespace App\Filament\Resources\CommunicationTemplates\Pages;

use App\Enums\TemplateStatus;
use App\Filament\Resources\CommunicationTemplates\CommunicationTemplateResource;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\CommunicationTemplate;
use App\Services\Communication\CommunicationTemplateService;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
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

    /**
     * Phase 8.6 (D8.6-022): archiving a template the application sends by itself is allowed, but
     * says what it stops.
     */
    protected function afterSave(): void
    {
        /** @var CommunicationTemplate $record */
        $record = $this->getRecord();

        if ($record->status === TemplateStatus::Archived && ($warning = app(CommunicationTemplateService::class)->builtInArchiveWarning($record)) !== null) {
            Notification::make()->title('Automatic messages affected')->body($warning)->warning()->persistent()->send();
        }
    }
}
