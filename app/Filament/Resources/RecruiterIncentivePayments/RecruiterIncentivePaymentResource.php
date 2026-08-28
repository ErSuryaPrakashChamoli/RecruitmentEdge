<?php

namespace App\Filament\Resources\RecruiterIncentivePayments;

use App\Filament\Resources\RecruiterIncentivePayments\Pages\ListRecruiterIncentivePayments;
use App\Filament\Resources\RecruiterIncentivePayments\Schemas\RecruiterIncentivePaymentForm;
use App\Filament\Resources\RecruiterIncentivePayments\Tables\RecruiterIncentivePaymentsTable;
use App\Models\RecruiterIncentivePayment;
use App\Models\User;
use App\Services\HierarchyService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only "Payout History" — payments are recorded only via IncentiveApprovalService::pay(),
 * driven from the Incentive Calculations resource.
 */
class RecruiterIncentivePaymentResource extends Resource
{
    protected static ?string $model = RecruiterIncentivePayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Incentives';

    protected static ?string $navigationLabel = 'Payout History';

    public static function form(Schema $schema): Schema
    {
        return RecruiterIncentivePaymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruiterIncentivePaymentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var User $user */
        $user = Filament::auth()->user();

        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        if ($visibleIds === null) {
            return $query;
        }

        return $query->whereHas('calculation', fn (Builder $q) => $q->whereIn('employee_id', $visibleIds));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecruiterIncentivePayments::route('/'),
        ];
    }
}
