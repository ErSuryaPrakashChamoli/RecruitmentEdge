<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations;

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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('employee.first_name')->label('Recruiter')->formatStateUsing(fn ($record) => $record->employee->fullName()),
            TextEntry::make('candidate.full_name')->label('Candidate'),
            TextEntry::make('incentiveRule.name')->label('Rule'),
            TextEntry::make('period_start')->label('Period')->formatStateUsing(fn ($record) => $record->period_start->format('M Y')),
            TextEntry::make('achievement')->formatStateUsing(fn (?string $state) => $state !== null ? number_format((float) $state, 1).'%' : '—'),
            TextEntry::make('amount')->money('INR'),
            TextEntry::make('effective_amount')->label('Effective Amount')->state(fn ($record) => $record->effectiveAmount())->money('INR'),
            TextEntry::make('status')->badge(),
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
