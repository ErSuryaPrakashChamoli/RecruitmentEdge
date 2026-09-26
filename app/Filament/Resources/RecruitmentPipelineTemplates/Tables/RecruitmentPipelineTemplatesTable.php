<?php

namespace App\Filament\Resources\RecruitmentPipelineTemplates\Tables;

use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Filament\Resources\RecruitmentPipelineTemplates\RecruitmentPipelineTemplateResource;
use App\Models\RecruitmentPipelineTemplate;
use App\Services\PipelineTemplateService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RecruitmentPipelineTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['templateStages', 'requisitions']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (RecruitmentPipelineTemplate $record): ?string => $record->description),
                TextColumn::make('template_stages_count')->label('Stages'),
                TextColumn::make('version')->prefix('v'),
                TextColumn::make('requisitions_count')->label('Requisitions'),
                IconColumn::make('is_default')->label('Default')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('updated_at')->label('Last updated')->since()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                self::cloneAction(),
                self::setDefaultAction(),
                self::toggleActiveAction(),
            ])
            ->emptyStateHeading('No pipeline templates yet')
            ->emptyStateDescription('Create a template to reuse a hiring pipeline across requisitions.')
            ->emptyStateIcon('heroicon-o-queue-list');
    }

    public static function cloneAction(): Action
    {
        return Action::make('clone')
            ->label('Clone')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('create', RecruitmentPipelineTemplate::class))
            ->schema([
                TextInput::make('name')
                    ->default(fn (RecruitmentPipelineTemplate $record): string => "{$record->name} (Copy)")
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (RecruitmentPipelineTemplate $record, array $data, Action $action): void {
                $clone = InterviewsTable::guarded('Template could not be cloned', fn () => app(PipelineTemplateService::class)->clone($record, $data['name'], auth()->user()?->employee));

                Notification::make()->title('Template cloned')->success()->send();

                $action->redirect(RecruitmentPipelineTemplateResource::getUrl('edit', ['record' => $clone]));
            });
    }

    public static function setDefaultAction(): Action
    {
        return Action::make('setDefault')
            ->label('Make default')
            ->icon('heroicon-o-star')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('New requisitions will use this template unless another is chosen. Existing requisitions keep their pipeline.')
            ->visible(fn (RecruitmentPipelineTemplate $record): bool => ! $record->is_default && $record->is_active
                && (bool) auth()->user()?->can('update', $record))
            ->action(function (RecruitmentPipelineTemplate $record): void {
                InterviewsTable::guarded('Template could not be made default', fn () => app(PipelineTemplateService::class)->setDefault($record));

                Notification::make()->title('Default template updated')->success()->send();
            });
    }

    public static function toggleActiveAction(): Action
    {
        return Action::make('toggleActive')
            ->label(fn (RecruitmentPipelineTemplate $record): string => $record->is_active ? 'Deactivate' : 'Activate')
            ->icon(fn (RecruitmentPipelineTemplate $record): string => $record->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
            ->color(fn (RecruitmentPipelineTemplate $record): string => $record->is_active ? 'warning' : 'success')
            ->requiresConfirmation()
            ->visible(fn (RecruitmentPipelineTemplate $record): bool => ! $record->is_default && (bool) auth()->user()?->can('update', $record))
            ->action(function (RecruitmentPipelineTemplate $record): void {
                InterviewsTable::guarded('Template could not be updated', fn () => app(PipelineTemplateService::class)->setActive($record, ! $record->is_active));

                Notification::make()->title($record->is_active ? 'Template activated' : 'Template deactivated')->success()->send();
            });
    }
}
