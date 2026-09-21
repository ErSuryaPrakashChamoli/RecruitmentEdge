<?php

namespace App\Filament\Resources\RecruitmentIncentiveRules\RelationManagers;

use App\Enums\IncentivePayoutType;
use App\Models\RecruitmentIncentiveRule;
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
use Illuminate\Database\Eloquent\Model;

/**
 * Slab bands are entered in the owning rule's unit — occurrence count (e.g. joinings) for Slab by
 * count, achievement % for Slab by achievement — and hidden entirely for fixed-rate rules.
 */
class SlabsRelationManager extends RelationManager
{
    protected static string $relationship = 'slabs';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if ($ownerRecord instanceof RecruitmentIncentiveRule && $ownerRecord->payout_type === IncentivePayoutType::Fixed) {
            return false;
        }

        return parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        $isCount = $this->isCountBased();

        return $schema
            ->components([
                TextInput::make('achievement_min')
                    ->label($this->boundLabel('from'))
                    ->numeric()
                    ->integer($isCount)
                    ->minValue($isCount ? 1 : 0)
                    ->required(),
                TextInput::make('achievement_max')
                    ->label($this->boundLabel('to'))
                    ->numeric()
                    ->integer($isCount)
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
                    ->label($this->amountLabel())
                    ->prefix('₹')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        $isCount = $this->isCountBased();
        $formatBound = fn (mixed $state): string => $isCount ? number_format((float) $state) : (string) $state;

        return $table
            ->recordTitleAttribute('amount')
            ->description($this->tableDescription())
            ->defaultSort('achievement_min')
            ->columns([
                TextColumn::make('achievement_min')
                    ->label($isCount ? $this->boundLabel('from') : 'From %')
                    ->formatStateUsing($formatBound),
                TextColumn::make('achievement_max')
                    ->label($isCount ? $this->boundLabel('to') : 'To %')
                    ->formatStateUsing($formatBound)
                    ->placeholder('No limit'),
                TextColumn::make('amount')
                    ->label($this->amountLabel())
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

    private function rule(): RecruitmentIncentiveRule
    {
        /** @var RecruitmentIncentiveRule */
        return $this->getOwnerRecord();
    }

    private function isCountBased(): bool
    {
        return $this->rule()->payout_type === IncentivePayoutType::SlabByCount;
    }

    private function boundLabel(string $edge): string
    {
        return $this->isCountBased()
            ? ucfirst($this->rule()->trigger_event->countNoun()).' '.$edge
            : 'Achievement % '.$edge;
    }

    private function amountLabel(): string
    {
        return 'Amount per '.$this->rule()->trigger_event->occurrenceNoun();
    }

    private function tableDescription(): string
    {
        $rule = $this->rule();
        $basis = $this->isCountBased()
            ? 'the recruiter\'s number of '.$rule->trigger_event->countNoun()
            : 'the recruiter\'s achievement %';

        return 'Each '.$rule->trigger_event->occurrenceNoun().' pays the amount of the band matching '.$basis.' for the month. '
            .($rule->slab_upgrade_mode?->description() ?? '');
    }
}
