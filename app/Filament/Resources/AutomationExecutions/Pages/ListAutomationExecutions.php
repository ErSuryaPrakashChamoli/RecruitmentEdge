<?php

namespace App\Filament\Resources\AutomationExecutions\Pages;

use App\Enums\AutomationExecutionStatus;
use App\Filament\Resources\AutomationExecutions\AutomationExecutionResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAutomationExecutions extends ListRecords
{
    protected static string $resource = AutomationExecutionResource::class;

    public function getTabs(): array
    {
        $status = fn (AutomationExecutionStatus ...$statuses) => fn (Builder $query) => $query->whereIn('status', $statuses);

        return [
            'all' => Tab::make('All'),
            'failed' => Tab::make('Failures')->modifyQueryUsing($status(AutomationExecutionStatus::Failed, AutomationExecutionStatus::PartiallyCompleted))->badgeColor('danger')
                ->badge(fn () => AutomationExecutionResource::getEloquentQuery()->whereIn('status', [AutomationExecutionStatus::Failed, AutomationExecutionStatus::PartiallyCompleted])->count() ?: null),
            'pending' => Tab::make('Scheduled')->modifyQueryUsing($status(AutomationExecutionStatus::Pending, AutomationExecutionStatus::Running)),
            'completed' => Tab::make('Completed')->modifyQueryUsing($status(AutomationExecutionStatus::Completed)),
            'skipped' => Tab::make('Skipped')->modifyQueryUsing($status(AutomationExecutionStatus::Skipped, AutomationExecutionStatus::Cancelled)),
        ];
    }
}
