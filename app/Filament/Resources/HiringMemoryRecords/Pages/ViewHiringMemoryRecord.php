<?php

namespace App\Filament\Resources\HiringMemoryRecords\Pages;

use App\Enums\IntelligenceAiStatus;
use App\Filament\Concerns\GuardsDomainExceptions;
use App\Filament\Resources\HiringMemoryRecords\HiringMemoryRecordResource;
use App\Models\HiringMemoryRecord;
use App\Services\Intelligence\EvidenceLookup;
use App\Services\Intelligence\HiringMemoryService;
use App\Services\Intelligence\IntelligenceAiService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewHiringMemoryRecord extends ViewRecord
{
    use GuardsDomainExceptions;

    protected static string $resource = HiringMemoryRecordResource::class;

    protected function getHeaderActions(): array
    {
        /** @var HiringMemoryRecord $record */
        $record = $this->getRecord();

        return [
            Action::make('evidence')
                ->label('Why?')
                ->icon('heroicon-o-magnifying-glass-circle')
                ->color('gray')
                ->slideOver()
                ->modalWidth(Width::TwoExtraLarge)
                ->modalContent(fn () => view('filament.intelligence.evidence', ['rows' => app(EvidenceLookup::class)->for(auth()->user(), 'hiring_memory', $record->id)]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
            Action::make('aiSummary')
                ->label('Summarise with AI')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Sends the role-level facts of this record (no names, contact details or compensation) to the configured AI provider.')
                ->visible(fn () => auth()->user()?->can('ai.query') ?? false)
                ->disabled(fn () => $record->ai_status === IntelligenceAiStatus::Processing)
                ->action(function () use ($record): void {
                    $status = app(IntelligenceAiService::class)->requestMemorySummary($record, auth()->user());
                    Notification::make()->title($status === IntelligenceAiStatus::Unavailable ? 'AI is not configured' : 'AI summary requested')->color($status === IntelligenceAiStatus::Unavailable ? 'warning' : 'success')->send();
                }),
            Action::make('correct')
                ->label('Record a correction')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn () => auth()->user()?->can('correct', $record) ?? false)
                ->schema([
                    Select::make('fact')->options(collect($record->facts)->filter(fn ($value) => is_scalar($value) || $value === null)->keys()->mapWithKeys(fn ($key) => [$key => str_replace('_', ' ', ucfirst($key))])->all())->required(),
                    TextInput::make('value')->label('Correct value')->required(),
                    Textarea::make('reason')->required()->rows(2),
                ])
                ->modalDescription('Creates a new version with the corrected fact. The current version is kept as history.')
                ->action(function (array $data) use ($record): void {
                    $corrected = self::guarded('Could not record the correction', fn () => app(HiringMemoryService::class)->correct($record, [$data['fact'] => $data['value']], $data['reason'], auth()->user()));
                    Notification::make()->title("Correction recorded as v{$corrected->version}")->success()->send();
                    $this->redirect(HiringMemoryRecordResource::getUrl('view', ['record' => $corrected]));
                }),
        ];
    }
}
