<?php

namespace App\Filament\Resources\RecruiterActions\Tables;

use App\Enums\ActionPriority;
use App\Enums\RecruiterActionStatus;
use App\Enums\RecruiterActionType;
use App\Filament\Concerns\GuardsDomainExceptions;
use App\Models\Employee;
use App\Models\RecruiterAction;
use App\Models\User;
use App\Services\Automation\AutomationLinks;
use App\Services\HierarchyService;
use App\Services\RecruiterActionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RecruiterActionsTable
{
    use GuardsDomainExceptions;

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['owner:id,first_name,last_name', 'candidate:id,full_name', 'requisition:id,code', 'automationRule:id,name']))
            ->defaultSort('priority')
            ->columns([
                TextColumn::make('priority')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw("case priority when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end ".($direction === 'desc' ? 'desc' : 'asc'))
                        ->orderByRaw('due_at is null')
                        ->orderBy('due_at'))
                    ->badge()
                    ->formatStateUsing(fn (ActionPriority $state) => $state->label())
                    ->color(fn (ActionPriority $state) => $state->color()),
                TextColumn::make('title')
                    ->searchable()
                    ->wrap()
                    ->description(fn (RecruiterAction $record): ?string => str($record->suggested_action ?? $record->reason ?? '')->limit(70)->toString() ?: null),
                TextColumn::make('candidate.full_name')
                    ->label('Candidate')
                    ->wrap()
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn (RecruiterAction $record): ?string => $record->requisition?->code),
                TextColumn::make('due_at')
                    ->label('Due')
                    ->since()
                    ->sortable()
                    ->placeholder('No due time')
                    ->color(fn (RecruiterAction $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->tooltip(fn (RecruiterAction $record): ?string => $record->due_at?->toDayDateTimeString()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RecruiterActionStatus $state) => $state->label())
                    ->color(fn (RecruiterActionStatus $state) => $state->color()),
                TextColumn::make('owner.first_name')
                    ->label('Owner / source')
                    ->formatStateUsing(fn (RecruiterAction $record) => $record->owner?->fullName())
                    ->description(fn (RecruiterAction $record): string => $record->automationRule?->name ?? 'Manual')
                    ->wrap(),
                TextColumn::make('created_at')->label('Created')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('priority')->options(ActionPriority::options())->multiple(),
                SelectFilter::make('status')->options(RecruiterActionStatus::options())->multiple(),
                SelectFilter::make('action_type')->label('Type')->options(RecruiterActionType::options()),
                SelectFilter::make('requisition')->relationship('requisition', 'code')->searchable()->preload(),
                SelectFilter::make('automation_rule')->label('Automation source')->relationship('automationRule', 'name'),
                Filter::make('due')
                    ->schema([
                        DatePicker::make('due_from')->label('Due from'),
                        DatePicker::make('due_until')->label('Due until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['due_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('due_at', '>=', $date))
                        ->when($data['due_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('due_at', '<=', $date))),
            ])
            ->recordUrl(fn (RecruiterAction $record): ?string => AutomationLinks::for($record->subject ?? $record->candidateApplication))
            ->recordActions([
                ActionGroup::make([
                    self::startAction(),
                    self::completeAction(),
                    self::dismissAction(),
                    self::reassignAction(),
                ]),
            ])
            ->emptyStateHeading('Nothing needs your attention')
            ->emptyStateDescription('Actions created by automation rules, escalations and your manager appear here.')
            ->emptyStateIcon('heroicon-o-check-badge');
    }

    public static function canWork(RecruiterAction $record): bool
    {
        $user = auth()->user();

        return $user instanceof User && $record->isOpen() && $user->can('update', $record);
    }

    private static function startAction(): Action
    {
        return Action::make('start')
            ->label('Start')
            ->icon('heroicon-o-play')
            ->visible(fn (RecruiterAction $record) => self::canWork($record) && $record->status === RecruiterActionStatus::Open)
            ->action(function (RecruiterAction $record): void {
                self::guarded('Could not start the action', fn () => app(RecruiterActionService::class)->start($record, auth()->user()->employee));
                Notification::make()->title('Action started')->success()->send();
            });
    }

    private static function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Complete')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (RecruiterAction $record) => self::canWork($record))
            ->schema([Textarea::make('note')->label('What was done?')->rows(2)])
            ->action(function (RecruiterAction $record, array $data): void {
                self::guarded('Could not complete the action', fn () => app(RecruiterActionService::class)->complete($record, auth()->user()->employee, $data['note'] ?? null));
                Notification::make()->title('Action completed')->success()->send();
            });
    }

    private static function dismissAction(): Action
    {
        return Action::make('dismiss')
            ->label('Dismiss')
            ->icon('heroicon-o-x-circle')
            ->color('gray')
            ->visible(fn (RecruiterAction $record) => self::canWork($record))
            ->schema([Textarea::make('reason')->label('Why is this not needed?')->required()->rows(2)])
            ->action(function (RecruiterAction $record, array $data): void {
                self::guarded('Could not dismiss the action', fn () => app(RecruiterActionService::class)->dismiss($record, auth()->user()->employee, $data['reason']));
                Notification::make()->title('Action dismissed')->success()->send();
            });
    }

    private static function reassignAction(): Action
    {
        return Action::make('reassign')
            ->label('Reassign')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (RecruiterAction $record) => $record->isOpen() && (auth()->user()?->can('actions.manage') ?? false))
            ->schema([
                Select::make('owner_id')
                    ->label('New owner')
                    ->options(fn () => self::teamOptions())
                    ->searchable()
                    ->required(),
                Textarea::make('reason')->required()->rows(2),
            ])
            ->action(function (RecruiterAction $record, array $data): void {
                self::guarded('Could not reassign the action', fn () => app(RecruiterActionService::class)->reassign($record, Employee::query()->with('user')->findOrFail($data['owner_id']), auth()->user(), $data['reason']));
                Notification::make()->title('Action reassigned')->success()->send();
            });
    }

    /**
     * Active employees with a login inside the viewer's hierarchy.
     *
     * @return array<int, string>
     */
    public static function teamOptions(): array
    {
        $visible = app(HierarchyService::class)->visibleEmployeeIdsFor(auth()->user());

        return Employee::query()
            ->where('status', 'active')
            ->whereHas('user')
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('id', $visible))
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->fullName()])
            ->all();
    }
}
