<?php

namespace App\Filament\Resources\InterviewAvailabilitySlots\RelationManagers;

use App\Enums\SchedulingChannel;
use App\Enums\SlotBookingStatus;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\InterviewSlotBooking;
use App\Services\InterviewSchedulingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Bookings';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['candidate', 'candidateApplication']))
            ->defaultSort('booked_at', 'desc')
            ->columns([
                TextColumn::make('candidate.full_name')->label('Candidate'),
                TextColumn::make('candidateApplication.application_code')->label('Application'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (SlotBookingStatus $state) => $state->label())->color(fn (SlotBookingStatus $state) => $state->color()),
                TextColumn::make('channel')->formatStateUsing(fn (SchedulingChannel $state) => $state->label()),
                TextColumn::make('booked_at')->since(),
                TextColumn::make('cancellation_reason')->placeholder('—')->limit(40),
            ])
            ->recordActions([
                Action::make('cancelBooking')
                    ->label('Cancel booking')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (InterviewSlotBooking $record): bool => $record->isActive() && (bool) auth()->user()?->can('update', $this->getOwnerRecord()))
                    ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                    ->action(function (InterviewSlotBooking $record, array $data): void {
                        InterviewsTable::guarded('Booking could not be cancelled', fn () => app(InterviewSchedulingService::class)->cancelBooking($record, $data['reason'], auth()->user()?->employee));

                        Notification::make()->title('Booking cancelled')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No bookings yet');
    }
}
