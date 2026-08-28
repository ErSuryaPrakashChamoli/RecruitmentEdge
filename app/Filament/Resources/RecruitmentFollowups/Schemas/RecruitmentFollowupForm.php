<?php

namespace App\Filament\Resources\RecruitmentFollowups\Schemas;

use App\Enums\FollowupType;
use App\Models\Employee;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class RecruitmentFollowupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('candidate_application_id')
                    ->label('Application')
                    ->relationship('candidateApplication', 'application_code')
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('recruiter_id')
                    ->label('Recruiter')
                    ->relationship('recruiter', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                    ->default(fn () => Filament::auth()->user()?->employee_id)
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('followup_type')
                    ->options(collect(FollowupType::cases())->mapWithKeys(fn (FollowupType $t) => [$t->value => $t->label()]))
                    ->required(),
                DateTimePicker::make('followup_date')
                    ->default(now()->addDay())
                    ->required(),
                Textarea::make('remarks')
                    ->columnSpanFull(),
            ]);
    }
}
