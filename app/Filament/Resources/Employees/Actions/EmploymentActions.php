<?php

namespace App\Filament\Resources\Employees\Actions;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\EmployeeLifecycleService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * Phase 8.4: employment changes are explicit, confirmed actions that go through
 * EmployeeLifecycleService — making someone inactive suspends their login, reactivating lifts that
 * suspension. A separation is recorded under Administration → Separations.
 */
class EmploymentActions
{
    public static function deactivate(): Action
    {
        return Action::make('deactivateEmployment')
            ->label('Deactivate')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('The employee becomes inactive and their login is suspended until they are reactivated.')
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
            ->visible(fn (Employee $record): bool => $record->status === EmployeeStatus::Active && ! $record->trashed() && (bool) auth()->user()?->can('update', $record))
            ->action(fn (Employee $record, array $data) => self::perform(fn (EmployeeLifecycleService $service, User $actor) => $service->deactivate($record, $actor, $data['reason']), 'Employee deactivated; login suspended'));
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivateEmployment')
            ->label('Reactivate')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('The employee becomes active again. A login suspended because of the inactive status is restored; an access decision made separately is not.')
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
            ->visible(fn (Employee $record): bool => $record->status === EmployeeStatus::Inactive && ! $record->trashed() && (bool) auth()->user()?->can('update', $record))
            ->action(fn (Employee $record, array $data) => self::perform(fn (EmployeeLifecycleService $service, User $actor) => $service->reactivate($record, $actor, $data['reason']), 'Employee reactivated'));
    }

    /**
     * @param  callable(EmployeeLifecycleService, User): mixed  $operation
     */
    private static function perform(callable $operation, string $success): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $operation(app(EmployeeLifecycleService::class), $actor);
        } catch (DomainException $e) {
            Notification::make()->title('Employment could not be changed')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }

        Notification::make()->title($success)->success()->send();
    }
}
