<?php

namespace App\Filament\Resources\RecruitmentIncentiveRules\RelationManagers;

use App\Models\RecruitmentIncentiveSlab;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SlabsRelationManager extends RelationManager
{
    protected static string $relationship = 'slabs';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('achievement_min')
                    ->label('Achievement % from')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('achievement_max')
                    ->label('Achievement % to')
                    ->numeric()
                    ->helperText('Leave blank for no upper bound (only the highest slab may be open-ended). Both bounds are inclusive, so bands must not share an edge.')
                    ->rules([
                        fn (Get $get, ?RecruitmentIncentiveSlab $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                            $min = $get('achievement_min');

                            if (! is_numeric($min)) {
                                return;
                            }

                            $violation = RecruitmentIncentiveSlab::bandViolation(
                                (int) $this->getOwnerRecord()->getKey(),
                                (float) $min,
                                filled($value) ? (float) $value : null,
                                $record?->getKey(),
                            );

                            if ($violation !== null) {
                                $fail($violation);
                            }
                        },
                    ]),
                TextInput::make('amount')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount')
            ->defaultSort('achievement_min')
            ->columns([
                TextColumn::make('achievement_min')
                    ->label('From %'),
                TextColumn::make('achievement_max')
                    ->label('To %')
                    ->placeholder('No limit'),
                TextColumn::make('amount')
                    ->money('INR'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
