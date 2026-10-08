<?php

namespace App\Filament\Resources\AutomationExecutions;

use App\Enums\AutomationExecutionStatus;
use App\Enums\EscalationStatus;
use App\Filament\Concerns\GuardsDomainExceptions;
use App\Models\AutomationExecution;
use App\Services\Automation\AutomationEngine;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Manual retry (failed actions only) and cancellation, both audited by AutomationEngine.
 */
class AutomationExecutionActions
{
    use GuardsDomainExceptions;

    public static function retry(): Action
    {
        return Action::make('retry')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Only the actions that failed are run again; completed actions are never repeated.')
            ->visible(fn (AutomationExecution $record) => $record->isRetryable() && (auth()->user()?->can('retry', $record) ?? false))
            ->action(function (AutomationExecution $record): void {
                self::guarded('Could not retry', fn () => app(AutomationEngine::class)->retry($record, auth()->user()));
                Notification::make()->title('Retry queued')->success()->send();
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label('Cancel run / escalation')
            ->icon('heroicon-o-stop-circle')
            ->color('danger')
            ->schema([TextInput::make('reason')->required()->default('Cancelled manually')])
            ->visible(fn (AutomationExecution $record) => ($record->status === AutomationExecutionStatus::Pending || $record->escalations()->where('status', EscalationStatus::Pending)->exists()) && (auth()->user()?->can('retry', $record) ?? false))
            ->action(function (AutomationExecution $record, array $data): void {
                self::guarded('Could not cancel', fn () => app(AutomationEngine::class)->cancel($record, auth()->user(), $data['reason']));
                Notification::make()->title('Cancelled')->success()->send();
            });
    }
}
