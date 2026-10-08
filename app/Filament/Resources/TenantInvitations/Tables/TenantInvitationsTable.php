<?php

namespace App\Filament\Resources\TenantInvitations\Tables;

use App\Enums\InvitationStatus;
use App\Models\Role;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Services\Identity\TenantInvitationService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantInvitationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['inviter', 'employee']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')
                    ->description(fn (TenantInvitation $record): ?string => $record->name)
                    ->searchable(['email', 'name']),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InvitationStatus $state): string => $state->label())
                    ->color(fn (InvitationStatus $state): string => $state->color()),
                TextColumn::make('roles')
                    ->label('Roles')
                    ->badge()
                    ->state(fn (TenantInvitation $record): array => Role::query()->forCurrentTenant()->whereKey((array) $record->role_ids)->orderBy('name')->pluck('name')->all()),
                TextColumn::make('employee.employee_code')
                    ->label('Employee')
                    ->placeholder('—'),
                TextColumn::make('inviter.name')
                    ->label('Invited by')
                    ->placeholder('—'),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->since()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Sent')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(InvitationStatus::cases())->mapWithKeys(fn (InvitationStatus $status) => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                Action::make('resendInvitation')
                    ->label('Resend')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('A new link is emailed and the previous link stops working.')
                    ->visible(fn (TenantInvitation $record): bool => $record->status === InvitationStatus::Pending && (auth()->user()?->can('update', $record) ?? false))
                    ->action(fn (TenantInvitation $record) => self::perform(fn (User $actor) => app(TenantInvitationService::class)->resend($record, $actor), 'Invitation sent again')),
                Action::make('revokeInvitation')
                    ->label('Revoke')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The link stops working. The person can only join with a new invitation.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
                    ->visible(fn (TenantInvitation $record): bool => $record->status === InvitationStatus::Pending && (auth()->user()?->can('update', $record) ?? false))
                    ->action(fn (TenantInvitation $record, array $data) => self::perform(fn (User $actor) => app(TenantInvitationService::class)->revoke($record, $actor, $data['reason']), 'Invitation revoked')),
            ]);
    }

    /**
     * @param  callable(User): mixed  $operation
     */
    private static function perform(callable $operation, string $success): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $operation($actor);
        } catch (DomainException $e) {
            Notification::make()->title('Invitation not changed')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }

        Notification::make()->title($success)->success()->send();
    }
}
