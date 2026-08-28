<?php

namespace App\Filament\Resources\RecruiterPerformanceRules\Schemas;

use App\Enums\TargetMetric;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RecruiterPerformanceRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('metric')
                    ->options(collect(TargetMetric::cases())->mapWithKeys(fn (TargetMetric $m) => [$m->value => $m->label()]))
                    ->required(),
                TextInput::make('weightage')
                    ->label('Weightage (%)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->required(),
                DatePicker::make('effective_from')
                    ->default(now())
                    ->required(),
                DatePicker::make('effective_to'),
            ]);
    }
}
