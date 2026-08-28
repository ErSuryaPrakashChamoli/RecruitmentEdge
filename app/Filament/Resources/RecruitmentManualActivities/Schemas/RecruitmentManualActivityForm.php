<?php

namespace App\Filament\Resources\RecruitmentManualActivities\Schemas;

use App\Enums\TargetMetric;
use App\Models\Employee;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RecruitmentManualActivityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('recruiter_id')
                    ->label('Recruiter')
                    ->relationship('recruiter', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                    ->default(fn () => Filament::auth()->user()?->employee_id)
                    ->required()
                    ->searchable()
                    ->preload(),
                DatePicker::make('activity_date')
                    ->default(now())
                    ->required(),
                Select::make('metric')
                    ->options(collect(TargetMetric::cases())->mapWithKeys(fn (TargetMetric $m) => [$m->value => $m->label()]))
                    ->required(),
                TextInput::make('count')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                Textarea::make('remarks')
                    ->helperText('Explain the offline activity this covers — e.g. a field walk-in drive with no per-candidate system record.')
                    ->columnSpanFull(),
            ]);
    }
}
