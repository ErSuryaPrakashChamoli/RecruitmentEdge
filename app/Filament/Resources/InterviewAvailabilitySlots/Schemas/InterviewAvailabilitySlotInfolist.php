<?php

namespace App\Filament\Resources\InterviewAvailabilitySlots\Schemas;

use App\Enums\InterviewMode;
use App\Enums\InterviewSlotStatus;
use App\Models\InterviewAvailabilitySlot;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InterviewAvailabilitySlotInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Slot')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('window')->label('When')->state(fn (InterviewAvailabilitySlot $record) => $record->displayWindow())->columnSpan(2),
                        TextEntry::make('status')->badge()->formatStateUsing(fn (InterviewSlotStatus $state) => $state->label())->color(fn (InterviewSlotStatus $state) => $state->color()),
                        TextEntry::make('seats')->label('Booked')->state(fn (InterviewAvailabilitySlot $record) => "{$record->booked_count} / {$record->capacity}"),
                        TextEntry::make('interviewer.first_name')->label('Interviewer')->formatStateUsing(fn (InterviewAvailabilitySlot $record) => $record->interviewer?->fullName()),
                        TextEntry::make('requisition.code')->label('Position')->placeholder('Any position'),
                        TextEntry::make('round_name')->placeholder('—'),
                        TextEntry::make('mode')->formatStateUsing(fn (InterviewMode $state) => $state->label()),
                        TextEntry::make('location')->placeholder('—'),
                        TextEntry::make('meeting_link')->placeholder('—'),
                        TextEntry::make('booking_closes')->label('Bookings close')->state(fn (InterviewAvailabilitySlot $record) => $record->bookingClosesAt()->setTimezone($record->timezone)->format('d M Y h:i A')),
                        TextEntry::make('cancellation_reason')->placeholder('—'),
                    ]),
            ]);
    }
}
