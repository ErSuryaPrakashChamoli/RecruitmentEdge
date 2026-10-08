<?php

namespace App\Filament\Resources\TalentPools\Schemas;

use App\Enums\TalentPoolVisibility;
use App\Filament\Support\ActiveMasterDataOptions;
use App\Models\Employee;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TalentPoolForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Talent pool')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('visibility')
                            ->options(TalentPoolVisibility::options())
                            ->helperText(fn ($state): ?string => TalentPoolVisibility::tryFrom((string) $state)?->description())
                            ->default(TalentPoolVisibility::Team->value)
                            ->live()
                            ->required(),
                        Select::make('owner_id')
                            ->label('Owner')
                            ->relationship('owner', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->default(fn () => auth()->user()?->employee_id)
                            ->searchable()
                            ->preload(),
                        Select::make('department_id')
                            ->relationship('department', 'name', ActiveMasterDataOptions::scope('department_id'))
                            ->searchable()
                            ->preload(),
                        TagsInput::make('tags')
                            ->placeholder('e.g. sales, bengaluru, java')
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->columnSpanFull(),
                        Textarea::make('criteria')
                            ->label('Criteria')
                            ->helperText('Who belongs in this pool — used by recruiters now and by future talent rediscovery.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
