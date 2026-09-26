<?php

namespace App\Filament\Resources\RecruitmentCampaigns\Tables;

use App\Enums\CampaignStatus;
use App\Models\RecruitmentCampaign;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecruitmentCampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('owner')->withCount('applications')->withSum('costs', 'amount'))
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (RecruitmentCampaign $record) => $record->code),
                TextColumn::make('status')->badge()->formatStateUsing(fn (CampaignStatus $state) => $state->label())->color(fn (CampaignStatus $state) => $state->color()),
                TextColumn::make('owner.first_name')->label('Owner')->formatStateUsing(fn (RecruitmentCampaign $record) => $record->owner?->fullName()),
                TextColumn::make('starts_on')->date()->sortable()->placeholder('—'),
                TextColumn::make('ends_on')->date()->placeholder('—'),
                TextColumn::make('applications_count')->label('Applications'),
                TextColumn::make('costs_sum_amount')->label('Spend')->money('INR')->placeholder('—'),
                TextColumn::make('budget')->money('INR')->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->options(CampaignStatus::options())])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('No campaigns yet')
            ->emptyStateIcon('heroicon-o-rocket-launch');
    }
}
