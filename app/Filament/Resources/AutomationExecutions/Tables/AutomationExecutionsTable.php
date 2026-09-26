<?php

namespace App\Filament\Resources\AutomationExecutions\Tables;

use App\Enums\AutomationExecutionStatus;
use App\Filament\Resources\AutomationExecutions\AutomationExecutionActions;
use App\Models\AutomationExecution;
use App\Services\Automation\AutomationEventRegistry;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AutomationExecutionsTable
{
    public static function configure(Table $table, bool $showRule = true): Table
    {
        $events = app(AutomationEventRegistry::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['rule:id,name', 'ruleVersion:id,version', 'candidateApplication.candidate:id,full_name']))
            ->defaultSort('created_at', 'desc')
            ->columns(array_filter([
                $showRule ? TextColumn::make('rule.name')->label('Rule')->searchable()->wrap() : null,
                TextColumn::make('ruleVersion.version')->label('Ver.')->prefix('v')->placeholder('—'),
                TextColumn::make('trigger')->formatStateUsing(fn (string $state) => $events->find($state)?->label ?? $state)->toggleable(),
                TextColumn::make('record')
                    ->state(fn (AutomationExecution $record) => trim(class_basename($record->subject_type).' #'.$record->subject_id.' '.($record->candidateApplication?->candidate?->full_name ?? '')))
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (AutomationExecutionStatus $state) => $state->label())
                    ->color(fn (AutomationExecutionStatus $state) => $state->color())
                    ->description(fn (AutomationExecution $record) => $record->failure_reason ?? $record->skip_reason)
                    ->wrap(),
                TextColumn::make('retry_count')->label('Retries')->numeric()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('scheduled_for')->label('Due')->since()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Triggered')->since()->sortable(),
            ]))
            ->filters([
                SelectFilter::make('status')->options(AutomationExecutionStatus::options())->multiple(),
                SelectFilter::make('rule')->relationship('rule', 'name'),
                SelectFilter::make('trigger')->options(collect($events->options())->collapse()->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                AutomationExecutionActions::retry(),
            ])
            ->emptyStateHeading('No automation runs yet')
            ->emptyStateIcon('heroicon-o-queue-list');
    }
}
