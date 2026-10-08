<?php

namespace App\Filament\Resources\CalendarConnections\Tables;

use App\Models\CalendarConnection;
use App\Services\Integrations\Calendar\CalendarConnectionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CalendarConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.first_name')->label('Employee')->formatStateUsing(fn (CalendarConnection $record) => $record->employee?->fullName()),
                TextColumn::make('provider')->formatStateUsing(fn (string $state) => $state === 'google_calendar' ? 'Google Calendar' : 'Microsoft 365'),
                TextColumn::make('account_email')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'active' => 'success', 'error' => 'danger', default => 'gray'
                }),
                TextColumn::make('last_error')->placeholder('—')->limit(50)->toggleable(),
                TextColumn::make('last_synced_at')->since()->placeholder('Never'),
            ])
            ->recordActions([
                Action::make('disconnect')
                    ->label('Disconnect')
                    ->icon('heroicon-o-link-slash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (CalendarConnection $record): bool => $record->status !== 'disconnected' && (bool) auth()->user()?->can('update', $record))
                    ->action(function (CalendarConnection $record): void {
                        app(CalendarConnectionService::class)->disconnect($record);
                        Notification::make()->title('Calendar disconnected')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No calendar connected')
            ->emptyStateDescription('Connect your Google or Microsoft calendar so interviews appear there automatically and self-scheduling avoids your busy times.')
            ->emptyStateIcon('heroicon-o-calendar');
    }
}
