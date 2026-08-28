<?php

namespace App\Filament\Resources\RecruitmentRejectionReasons\Schemas;

use App\Enums\RejectionCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class RecruitmentRejectionReasonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                Select::make('category')
                    ->options(collect(RejectionCategory::cases())->mapWithKeys(fn (RejectionCategory $c) => [$c->value => $c->label()]))
                    ->default(RejectionCategory::General)
                    ->required(),
                Toggle::make('is_active')
                    ->required()
                    ->default(true),
            ]);
    }
}
