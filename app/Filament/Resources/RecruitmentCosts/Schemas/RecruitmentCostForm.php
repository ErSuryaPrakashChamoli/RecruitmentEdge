<?php

namespace App\Filament\Resources\RecruitmentCosts\Schemas;

use App\Enums\RecruitmentCostType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RecruitmentCostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('cost_type')
                    ->options(collect(RecruitmentCostType::cases())->mapWithKeys(fn (RecruitmentCostType $t) => [$t->value => $t->label()]))
                    ->required(),
                TextInput::make('amount')
                    ->numeric()
                    ->required(),
                DatePicker::make('incurred_on')
                    ->default(now())
                    ->required(),
                Select::make('requisition_id')
                    ->label('Requisition (optional)')
                    ->relationship('requisition', 'code')
                    ->searchable()
                    ->preload(),
                Select::make('department_id')
                    ->label('Department (optional)')
                    ->relationship('department', 'name')
                    ->searchable()
                    ->preload(),
                Textarea::make('remarks')
                    ->columnSpanFull(),
            ]);
    }
}
