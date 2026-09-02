<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations;

use App\Enums\IncentiveCalculationStatus;
use App\Filament\Resources\RecruiterIncentiveCalculations\Pages\ListRecruiterIncentiveCalculations;
use App\Filament\Resources\RecruiterIncentiveCalculations\Pages\ViewRecruiterIncentiveCalculation;
use App\Filament\Resources\RecruiterIncentiveCalculations\RelationManagers\AdjustmentsRelationManager;
use App\Filament\Resources\RecruiterIncentiveCalculations\RelationManagers\ApprovalsRelationManager;
use App\Filament\Resources\RecruiterIncentiveCalculations\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\RecruiterIncentiveCalculations\Schemas\RecruiterIncentiveCalculationForm;
use App\Filament\Resources\RecruiterIncentiveCalculations\Tables\RecruiterIncentiveCalculationsTable;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\User;
use App\Services\HierarchyService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * No create/edit pages: calculations come only from RecruiterIncentiveCalculator. This one
 * resource serves Calculations, Approval, and Statements from your nav list — filtering by status
 * and recruiter covers the "Statements" and "Approval" views without duplicating the table.
 */
class RecruiterIncentiveCalculationResource extends Resource
{
    protected static ?string $model = RecruiterIncentiveCalculation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Incentives';

    protected static ?string $navigationLabel = 'Incentive Calculations';

    public static function form(Schema $schema): Schema
    {
        return RecruiterIncentiveCalculationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruiterIncentiveCalculationsTable::configure($table);
    }

    /**
     * A transparent statement: who/what this is for, which slab of the rule matched their
     * achievement %, and the resulting amount before/after adjustments. There is no "base x
     * multiplier" in this domain — a rule's slabs are flat amounts per achievement-% band (see
     * RecruitmentIncentiveSlab::matches()) — so the statement shows that real shape, not an
     * invented one. Adjustment-by-adjustment detail stays in the Adjustments relation manager tab.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Recruiter & Candidate')
                ->columns(3)
                ->schema([
                    TextEntry::make('employee.first_name')->label('Recruiter')->formatStateUsing(fn ($record) => $record->employee->fullName()),
                    TextEntry::make('candidate.full_name')->label('Candidate'),
                    TextEntry::make('period_start')->label('Period')->formatStateUsing(fn ($record) => $record->period_start->format('M Y')),
                ]),
            Section::make('Calculation')
                ->description('Which slab of the rule matched this recruiter\'s achievement %, and the resulting amount.')
                ->columns(3)
                ->schema([
                    TextEntry::make('incentiveRule.name')->label('Rule'),
                    TextEntry::make('achievement')->label('Achievement')->formatStateUsing(fn (?string $state) => $state !== null ? number_format((float) $state, 1).'%' : '—'),
                    TextEntry::make('incentiveSlab.achievement_min')
                        ->label('Slab Band')
                        ->formatStateUsing(fn ($record) => $record->incentiveSlab === null ? '—' : number_format((float) $record->incentiveSlab->achievement_min, 1).'% – '
                            .($record->incentiveSlab->achievement_max !== null ? number_format((float) $record->incentiveSlab->achievement_max, 1).'%' : 'uncapped')),
                    TextEntry::make('incentiveSlab.amount')->label('Slab Amount')->money('INR')->placeholder('—'),
                    TextEntry::make('amount')->label('Calculated Amount')->money('INR'),
                    TextEntry::make('effective_amount')->label('Effective Amount (after adjustments)')->state(fn ($record) => $record->effectiveAmount())->money('INR'),
                ]),
            Section::make('Status')
                ->schema([
                    TextEntry::make('status')->badge()->color(fn (IncentiveCalculationStatus $state) => $state->color()),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var User $user */
        $user = Filament::auth()->user();

        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        return $visibleIds === null ? $query : $query->whereIn('employee_id', $visibleIds);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            ApprovalsRelationManager::class,
            AdjustmentsRelationManager::class,
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecruiterIncentiveCalculations::route('/'),
            'view' => ViewRecruiterIncentiveCalculation::route('/{record}'),
        ];
    }
}
