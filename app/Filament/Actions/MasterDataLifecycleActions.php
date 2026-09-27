<?php

namespace App\Filament\Actions;

use App\Models\User;
use App\Services\MasterDataLifecycleService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.6 (D8.6-001/002/003): the master-data lifecycle as explicit, confirmed actions on every
 * governed master-data resource — Deactivate, Activate, Archive and Restore, each through
 * MasterDataLifecycleService (reason, in-use check, authorization, audit). There is no permanent
 * delete and no bulk variant.
 *
 * @see MasterDataLifecycleService
 */
class MasterDataLifecycleActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [self::deactivate(), self::activate(), self::archive(), self::restore()];
    }

    public static function deactivate(): Action
    {
        return Action::make('deactivateMasterData')
            ->label('Deactivate')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('It stays on existing records but can no longer be chosen for new ones.')
            ->schema([self::reasonField()])
            ->visible(fn (Model $record): bool => ! $record->trashed() && (bool) $record->is_active && (bool) auth()->user()?->can('update', $record))
            ->action(fn (Model $record, array $data) => self::perform(fn (MasterDataLifecycleService $service, User $actor) => $service->deactivate($actor, $record, $data['reason']), 'Deactivated'));
    }

    public static function activate(): Action
    {
        return Action::make('activateMasterData')
            ->label('Activate')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->requiresConfirmation()
            ->schema([Textarea::make('reason')->label('Reason (optional)')->maxLength(1000)])
            ->visible(fn (Model $record): bool => ! $record->trashed() && ! $record->is_active && (bool) auth()->user()?->can('update', $record))
            ->action(fn (Model $record, array $data) => self::perform(fn (MasterDataLifecycleService $service, User $actor) => $service->activate($actor, $record, $data['reason'] ?? null), 'Activated'));
    }

    public static function archive(): Action
    {
        return Action::make('archiveMasterData')
            ->label('Archive')
            ->icon('heroicon-o-archive-box')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Archived records are hidden from lists and pickers but stay on every historical record. Refused while open work still uses it.')
            ->schema([self::reasonField()])
            ->visible(fn (Model $record): bool => ! $record->trashed() && (bool) auth()->user()?->can('delete', $record))
            ->action(fn (Model $record, array $data) => self::perform(fn (MasterDataLifecycleService $service, User $actor) => $service->archive($actor, $record, $data['reason']), 'Archived'));
    }

    public static function restore(): Action
    {
        return Action::make('restoreMasterData')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('It comes back as inactive; activate it separately when it should be chosen again.')
            ->schema([self::reasonField()])
            ->visible(fn (Model $record): bool => $record->trashed() && (bool) auth()->user()?->can('restore', $record))
            ->action(fn (Model $record, array $data) => self::perform(fn (MasterDataLifecycleService $service, User $actor) => $service->restore($actor, $record, $data['reason']), 'Restored as inactive'));
    }

    private static function reasonField(): Textarea
    {
        return Textarea::make('reason')->label('Reason')->required()->maxLength(1000);
    }

    /**
     * @param  callable(MasterDataLifecycleService, User): mixed  $operation
     */
    private static function perform(callable $operation, string $success): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $operation(app(MasterDataLifecycleService::class), $actor);
        } catch (DomainException|AuthorizationException $e) {
            Notification::make()->title('Not changed')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }

        Notification::make()->title($success)->success()->send();
    }
}
