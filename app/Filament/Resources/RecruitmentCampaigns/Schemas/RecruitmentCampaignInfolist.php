<?php

namespace App\Filament\Resources\RecruitmentCampaigns\Schemas;

use App\Enums\CampaignStatus;
use App\Models\RecruitmentCampaign;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RecruitmentCampaignInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Campaign')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('code')->label('Tracking code')->copyable(),
                        TextEntry::make('status')->badge()->formatStateUsing(fn (CampaignStatus $state) => $state->label())->color(fn (CampaignStatus $state) => $state->color()),
                        TextEntry::make('owner.first_name')->label('Owner')->formatStateUsing(fn (RecruitmentCampaign $record) => $record->owner?->fullName())->placeholder('—'),
                        TextEntry::make('starts_on')->date()->placeholder('—'),
                        TextEntry::make('ends_on')->date()->placeholder('—'),
                        TextEntry::make('budget')->money('INR')->placeholder('—'),
                        TextEntry::make('target_hires')->placeholder('—'),
                        TextEntry::make('requisitions.code')->label('Requisitions')->badge()->placeholder('None'),
                        TextEntry::make('sources.name')->label('Sources')->badge()->placeholder('None'),
                        TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
