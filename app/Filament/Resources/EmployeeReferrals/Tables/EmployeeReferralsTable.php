<?php

namespace App\Filament\Resources\EmployeeReferrals\Tables;

use App\Enums\ReferralIncentiveStatus;
use App\Enums\ReferralStatus;
use App\Filament\Resources\EmployeeReferrals\Actions\ReferralActions;
use App\Models\EmployeeReferral;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeeReferralsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['referrer', 'candidate', 'requisition', 'candidateApplication']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('referral_code')->label('Referral')->searchable()->sortable(),
                TextColumn::make('candidate.full_name')->label('Candidate')->searchable(),
                TextColumn::make('referrer.first_name')
                    ->label('Referred by')
                    ->formatStateUsing(fn (EmployeeReferral $record) => $record->referrer?->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('requisition.code')->label('Position')->placeholder('General'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ReferralStatus $state) => $state->label())
                    ->color(fn (ReferralStatus $state) => $state->color()),
                TextColumn::make('incentive_status')
                    ->label('Bonus')
                    ->badge()
                    ->formatStateUsing(fn (ReferralIncentiveStatus $state) => $state->label())
                    ->color(fn (ReferralIncentiveStatus $state) => $state->color())
                    ->toggleable(),
                TextColumn::make('referred_at')->label('Referred')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ReferralStatus::options())->multiple(),
                Filter::make('mine')
                    ->label('My referrals')
                    ->query(fn (Builder $q) => $q->where('referrer_id', auth()->user()?->employee_id)),
                Filter::make('pending_review')
                    ->label('Awaiting review')
                    ->query(fn (Builder $q) => $q->whereIn('status', [ReferralStatus::Submitted, ReferralStatus::UnderReview])),
            ])
            ->recordActions([
                ...ReferralActions::all(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No referrals yet')
            ->emptyStateDescription('Know someone great? Submit a referral for an open position.')
            ->emptyStateIcon('heroicon-o-user-group');
    }
}
