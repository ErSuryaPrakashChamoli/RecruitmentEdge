<?php

namespace App\Filament\Resources\CandidatePortalAccounts\Tables;

use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\CandidatePortalAccount;
use App\Services\CandidatePortalService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CandidatePortalAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('candidate'))
            ->defaultSort('invited_at', 'desc')
            ->columns([
                TextColumn::make('candidate.full_name')->label('Candidate')->searchable(),
                TextColumn::make('email')->searchable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('password_set_at')->label('Activated')->since()->placeholder('Pending'),
                TextColumn::make('last_login_at')->label('Last sign-in')->since()->placeholder('Never')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                self::resendAction(),
                self::revokeAction(),
            ])
            ->emptyStateHeading('No candidates have portal access yet')
            ->emptyStateDescription('Invite a candidate from their Candidate 360 page.')
            ->emptyStateIcon('heroicon-o-key');
    }

    public static function resendAction(): Action
    {
        return Action::make('resend')
            ->label(fn (CandidatePortalAccount $record): string => $record->is_active ? 'Resend link' : 'Reactivate')
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->visible(fn (CandidatePortalAccount $record): bool => (bool) auth()->user()?->can('update', $record))
            ->action(function (CandidatePortalAccount $record): void {
                InterviewsTable::guarded('Link could not be sent', fn () => app(CandidatePortalService::class)->invite($record->candidate, auth()->user()?->employee, $record->email));

                Notification::make()->title('Portal link sent')->success()->send();
            });
    }

    public static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (CandidatePortalAccount $record): bool => $record->is_active && (bool) auth()->user()?->can('update', $record))
            ->action(function (CandidatePortalAccount $record): void {
                app(CandidatePortalService::class)->deactivate($record, auth()->user()?->employee);

                Notification::make()->title('Portal access revoked')->success()->send();
            });
    }
}
