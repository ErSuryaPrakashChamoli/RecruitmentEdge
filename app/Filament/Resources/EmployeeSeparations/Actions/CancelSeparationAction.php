<?php

namespace App\Filament\Resources\EmployeeSeparations\Actions;

use App\Models\EmployeeSeparation;
use App\Models\User;
use App\Services\Identity\EmployeeLifecycleService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * Phase 8.4: cancelling a separation (withdrawn resignation, recorded in error) is an explicit,
 * confirmed, audited action with a reason. It never restores access by itself.
 */
class CancelSeparationAction
{
    public static function make(): Action
    {
        return Action::make('cancelSeparation')
            ->label('Cancel separation')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(fn (EmployeeSeparation $record): string => $record->effective_applied_at !== null
                ? 'This separation has already taken effect. Employment becomes active again, but their login stays revoked — restore access separately (it starts with the base role). Outcomes recorded from this separation are voided.'
                : 'The separation is withdrawn; nothing else changes.')
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
            ->visible(fn (EmployeeSeparation $record): bool => $record->cancelled_at === null && (bool) auth()->user()?->can('employees.separation.cancel'))
            ->action(function (EmployeeSeparation $record, array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                try {
                    app(EmployeeLifecycleService::class)->cancelSeparation($record, $actor, $data['reason']);
                } catch (DomainException $e) {
                    Notification::make()->title('Separation could not be cancelled')->body($e->getMessage())->danger()->persistent()->send();

                    throw new Halt;
                }

                Notification::make()->title('Separation cancelled')->success()->send();
            });
    }
}
