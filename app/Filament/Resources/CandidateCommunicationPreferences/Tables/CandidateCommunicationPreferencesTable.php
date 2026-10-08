<?php

namespace App\Filament\Resources\CandidateCommunicationPreferences\Tables;

use App\Enums\CommunicationChannel;
use App\Enums\PreferenceStatus;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\CandidateCommunicationPreference;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CandidateCommunicationPreferencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('candidate:id,full_name,candidate_code'))
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (CandidateCommunicationPreference $record): string => CandidateResource::getUrl('view', ['record' => $record->candidate_id]))
            ->columns([
                TextColumn::make('candidate.full_name')->label('Candidate')->searchable(),
                TextColumn::make('channel')->formatStateUsing(fn (CommunicationChannel $state) => $state->label()),
                TextColumn::make('status')->badge()->formatStateUsing(fn (PreferenceStatus $state) => $state->label())->color(fn (PreferenceStatus $state) => $state->color()),
                TextColumn::make('source')->placeholder('—'),
                TextColumn::make('reason')->limit(50)->placeholder('—')->toggleable(),
                TextColumn::make('updated_at')->label('Changed')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel')->options(CommunicationChannel::options()),
                SelectFilter::make('status')->options(PreferenceStatus::options()),
            ])
            ->recordActions([])
            ->emptyStateHeading('No recorded preferences')
            ->emptyStateDescription('Candidates without a record here are "Unknown": email and SMS allowed unless they opt out; WhatsApp needs consent.')
            ->emptyStateIcon('heroicon-o-adjustments-horizontal');
    }
}
