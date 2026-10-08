<?php

namespace App\Filament\Resources\InterviewAvailabilitySlots\Schemas;

use App\Enums\InterviewMode;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\Interviewer;
use DateTimeZone;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Publishing availability. Date-times are entered as wall-clock times in the chosen timezone
 * (the pickers do no conversion; app.timezone is UTC) and InterviewSchedulingService converts them
 * to UTC for storage.
 */
class InterviewAvailabilitySlotForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Availability')
                    ->columns(3)
                    ->schema([
                        Select::make('interviewer_id')
                            ->label('Interviewer')
                            ->options(fn (): array => Interviewer::selectOptions())
                            ->searchable()
                            ->required(),
                        Select::make('requisition_id')
                            ->label('Position')
                            ->helperText('Leave empty to offer the slot for any position.')
                            ->options(fn (): array => RecruitmentRequisitionResource::applicationTargetQuery()->pluck('code', 'id')->all())
                            ->searchable(),
                        TextInput::make('round_name')->maxLength(255),
                        Select::make('timezone')
                            ->options(fn (): array => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                            ->default('Asia/Kolkata')
                            ->searchable()
                            ->required(),
                        DateTimePicker::make('starts_at')
                            ->label('First slot starts (local time)')
                            ->seconds(false)
                            ->required(),
                        TextInput::make('duration_minutes')
                            ->label('Slot length (minutes)')
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(480)
                            ->default(45)
                            ->required(),
                        TextInput::make('count')
                            ->label('Number of consecutive slots')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(50)
                            ->default(1)
                            ->required(),
                        TextInput::make('capacity')
                            ->label('Candidates per slot')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(50)
                            ->default(1)
                            ->required(),
                        DateTimePicker::make('bookable_until')
                            ->label('Stop accepting bookings at (local time)')
                            ->seconds(false),
                    ]),
                Section::make('Meeting')
                    ->columns(3)
                    ->schema([
                        Select::make('mode')
                            ->options(collect(InterviewMode::cases())->mapWithKeys(fn (InterviewMode $m) => [$m->value => $m->label()]))
                            ->default(InterviewMode::VideoCall->value)
                            ->required(),
                        TextInput::make('location')->maxLength(255),
                        TextInput::make('meeting_link')->url()->maxLength(255),
                    ]),
            ]);
    }
}
