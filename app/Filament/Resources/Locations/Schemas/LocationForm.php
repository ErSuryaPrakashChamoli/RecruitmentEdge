<?php

namespace App\Filament\Resources\Locations\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                // Phase 8.6 (D8.6-007): the code identifies the record in history — set once.
                TextInput::make('code')
                    ->required()
                    ->maxLength(50)
                    ->scopedUnique(ignoreRecord: true)
                    ->disabledOn('edit'),
                TextInput::make('city')
                    ->maxLength(255),
                TextInput::make('state')
                    ->maxLength(255),
                TextInput::make('country')
                    ->maxLength(255),
                // Status changes after creation are the Deactivate/Activate/Archive actions (with a reason).
                Toggle::make('is_active')
                    ->required()
                    ->default(true)
                    ->visibleOn('create'),
            ]);
    }
}
