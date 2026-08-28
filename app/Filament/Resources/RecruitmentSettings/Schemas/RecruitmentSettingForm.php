<?php

namespace App\Filament\Resources\RecruitmentSettings\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RecruitmentSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('value')
                    ->required(),
                Select::make('type')
                    ->options([
                        'string' => 'String',
                        'int' => 'Integer',
                        'bool' => 'Boolean',
                        'json' => 'JSON',
                    ])
                    ->default('string')
                    ->required(),
                TextInput::make('group')
                    ->default('general')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
            ]);
    }
}
