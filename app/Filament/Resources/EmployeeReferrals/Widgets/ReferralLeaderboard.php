<?php

namespace App\Filament\Resources\EmployeeReferrals\Widgets;

use App\Enums\ReferralStatus;
use App\Models\Employee;
use App\Models\EmployeeReferral;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Top referrers by joins then referrals, over referrals the viewer can see.
 */
class ReferralLeaderboard extends TableWidget
{
    protected static ?string $heading = 'Referral leaderboard';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        $visible = EmployeeReferral::query()->visibleTo($user)->select('id');

        return $table
            ->query(fn (): Builder => Employee::query()
                ->whereHas('referrals', fn (Builder $q) => $q->whereIn('id', $visible))
                ->withCount([
                    'referrals as referrals_total' => fn (Builder $q) => $q->whereIn('id', $visible),
                    'referrals as referrals_joined' => fn (Builder $q) => $q->whereIn('id', $visible)->where('status', ReferralStatus::Joined),
                ])
                ->orderByDesc('referrals_joined')
                ->orderByDesc('referrals_total'))
            ->columns([
                TextColumn::make('first_name')->label('Employee')->formatStateUsing(fn (Employee $record) => $record->fullName()),
                TextColumn::make('referrals_total')->label('Referrals'),
                TextColumn::make('referrals_joined')->label('Joined'),
            ])
            ->paginated([5])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No referrals yet');
    }
}
