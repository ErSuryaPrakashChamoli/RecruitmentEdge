<?php

namespace App\Filament\Resources\AutomationEscalations;

use App\Enums\EscalationStatus;
use App\Filament\Concerns\GuardsDomainExceptions;
use App\Filament\Resources\AutomationEscalations\Pages\ListAutomationEscalations;
use App\Filament\Resources\AutomationExecutions\AutomationExecutionResource;
use App\Models\AutomationEscalation;
use App\Services\Automation\EscalationService;
use App\Services\Automation\RecipientResolver;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 6 escalations: every scheduled, sent, stopped (resolved first) or failed escalation step,
 * scoped like execution history. Pending steps can be stopped by hand (audited).
 */
class AutomationEscalationResource extends Resource
{
    use GuardsDomainExceptions;

    protected static ?string $model = AutomationEscalation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Escalations';

    protected static ?int $navigationSort = 6;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('execution', fn (Builder $execution) => AutomationExecutionResource::scope($execution));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['rule:id,name', 'recipientEmployee:id,first_name,last_name', 'execution.candidateApplication.candidate:id,full_name']))
            ->defaultSort('due_at', 'desc')
            ->columns([
                TextColumn::make('rule.name')->label('Rule')->searchable()->wrap(),
                TextColumn::make('execution.candidateApplication.candidate.full_name')->label('Candidate')->placeholder('—'),
                TextColumn::make('step')->prefix('Step '),
                TextColumn::make('target')->label('Level')->formatStateUsing(fn (string $state) => RecipientResolver::label($state)),
                TextColumn::make('recipientEmployee.first_name')->label('Escalated to')->formatStateUsing(fn (AutomationEscalation $record) => $record->recipientEmployee?->fullName())->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (EscalationStatus $state) => $state->label())->color(fn (EscalationStatus $state) => $state->color()),
                TextColumn::make('outcome')->wrap()->placeholder('—'),
                TextColumn::make('due_at')->label('Due')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(EscalationStatus::options()),
                SelectFilter::make('rule')->relationship('rule', 'name'),
            ])
            ->recordUrl(fn (AutomationEscalation $record) => AutomationExecutionResource::getUrl('view', ['record' => $record->automation_execution_id]))
            ->recordActions([
                Action::make('stop')
                    ->icon('heroicon-o-stop-circle')
                    ->color('danger')
                    ->schema([TextInput::make('reason')->required()->default('Resolved manually')])
                    ->visible(fn (AutomationEscalation $record) => $record->status === EscalationStatus::Pending && (auth()->user()?->can('stop', $record) ?? false))
                    ->action(function (AutomationEscalation $record, array $data): void {
                        self::guarded('Could not stop the escalation', fn () => app(EscalationService::class)->stopRemaining($record->execution, $data['reason'].' (by '.auth()->user()->name.')'));
                        Notification::make()->title('Escalation stopped')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No escalations')
            ->emptyStateIcon('heroicon-o-arrow-trending-up');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomationEscalations::route('/'),
        ];
    }
}
