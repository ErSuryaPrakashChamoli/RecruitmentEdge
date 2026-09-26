<?php

namespace App\Filament\Resources\RecruiterActions\Pages;

use App\Enums\ActionPriority;
use App\Enums\RecruiterActionStatus;
use App\Enums\RecruiterActionType;
use App\Filament\Resources\CandidateApplications\Schemas\ApplicationPicker;
use App\Filament\Resources\RecruiterActions\RecruiterActionResource;
use App\Filament\Resources\RecruiterActions\Tables\RecruiterActionsTable;
use App\Filament\Resources\RecruiterActions\Widgets\SuggestedNextActions;
use App\Services\RecruiterActionService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * "My Actions": open items by priority, upcoming, overdue and closed, with deterministic Next Best
 * Action suggestions above. Managers (actions.manage) can assign work to their team.
 */
class ListRecruiterActions extends ListRecords
{
    protected static string $resource = RecruiterActionResource::class;

    protected static ?string $title = 'Action Center';

    public function getTabs(): array
    {
        $open = fn (Builder $query) => $query->whereIn('status', RecruiterActionStatus::openStatuses());
        $count = fn (callable $scope): int => $scope(RecruiterActionResource::getEloquentQuery())->count();

        $tabs = [
            'open' => Tab::make('All open')->modifyQueryUsing($open),
            'critical' => Tab::make('Critical')->modifyQueryUsing(fn (Builder $query) => $open($query)->where('priority', ActionPriority::Critical)),
            'high' => Tab::make('High')->modifyQueryUsing(fn (Builder $query) => $open($query)->where('priority', ActionPriority::High)),
            'medium' => Tab::make('Medium')->modifyQueryUsing(fn (Builder $query) => $open($query)->where('priority', ActionPriority::Medium)),
            'upcoming' => Tab::make('Upcoming')->modifyQueryUsing(fn (Builder $query) => $open($query)->where('due_at', '>', now())),
            'overdue' => Tab::make('Overdue')->modifyQueryUsing(fn (Builder $query) => $open($query)->where('due_at', '<', now())),
            'completed' => Tab::make('Closed')->modifyQueryUsing(fn (Builder $query) => $query->whereNotIn('status', RecruiterActionStatus::openStatuses())),
        ];

        $tabs['critical']->badge($count(fn (Builder $query) => $open($query)->where('priority', ActionPriority::Critical)) ?: null)->badgeColor('danger');
        $tabs['overdue']->badge($count(fn (Builder $query) => $open($query)->where('due_at', '<', now())) ?: null)->badgeColor('danger');

        return $tabs;
    }

    protected function getHeaderWidgets(): array
    {
        return [SuggestedNextActions::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('assign')
                ->label('Assign action')
                ->icon('heroicon-o-plus')
                ->visible(fn () => auth()->user()?->can('actions.manage') ?? false)
                ->schema([
                    TextInput::make('title')->required()->maxLength(200),
                    Select::make('owner_id')->label('Owner')->options(fn () => RecruiterActionsTable::teamOptions())->searchable()->required(),
                    Select::make('action_type')->label('Type')->options(RecruiterActionType::options())->default(RecruiterActionType::Custom->value)->required(),
                    Select::make('priority')->options(ActionPriority::options())->default(ActionPriority::Medium->value)->required(),
                    DateTimePicker::make('due_at')->label('Due')->seconds(false),
                    ApplicationPicker::make('candidate_application_id')->label('Candidate application (optional)'),
                    Textarea::make('reason')->rows(2),
                ])
                ->action(function (array $data): void {
                    $application = filled($data['candidate_application_id'] ?? null)
                        ? ApplicationPicker::selectableApplications()->findOrFail($data['candidate_application_id'])
                        : null;

                    RecruiterActionsTable::guarded('Could not assign the action', fn () => app(RecruiterActionService::class)->create([
                        ...$data,
                        'candidate_id' => $application?->candidate_id,
                        'requisition_id' => $application?->requisition_id,
                    ], auth()->user()->employee));

                    Notification::make()->title('Action assigned')->success()->send();
                }),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }
}
