<?php

namespace App\Filament\Resources\TalentPools\Schemas;

use App\Enums\TalentPoolStatus;
use App\Enums\TalentPoolVisibility;
use App\Filament\Support\MasterDataLabel;
use App\Models\TalentPool;
use App\Models\TalentPoolMembership;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TalentPoolInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Talent pool')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('status')->badge()->formatStateUsing(fn (TalentPoolStatus $state) => $state->label())->color(fn (TalentPoolStatus $state) => $state->color()),
                        TextEntry::make('visibility')->formatStateUsing(fn (TalentPoolVisibility $state) => $state->label()),
                        TextEntry::make('active_memberships_count')->label('Candidates')->state(fn (TalentPool $record) => $record->activeMemberships()->count()),
                        TextEntry::make('owner.first_name')->label('Owner')->formatStateUsing(fn (TalentPool $record) => $record->owner?->fullName())->placeholder('—'),
                        TextEntry::make('department.name')
                            ->formatStateUsing(MasterDataLabel::for('department'))->placeholder('—'),
                        TextEntry::make('createdBy.first_name')->label('Created by')->formatStateUsing(fn (TalentPool $record) => $record->createdBy?->fullName())->placeholder('—'),
                        TextEntry::make('tags')->badge()->placeholder('—'),
                        TextEntry::make('description')->placeholder('—')->columnSpan(2),
                        TextEntry::make('criteria')->placeholder('—')->columnSpan(2),
                    ]),
                Section::make('Recent activity')
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('recent_activity')
                            ->hiddenLabel()
                            ->state(fn (TalentPool $record) => $record->memberships()->with(['candidate:id,full_name,candidate_code', 'addedBy', 'removedBy'])->latest('updated_at')->limit(10)->get())
                            ->placeholder('No membership changes yet.')
                            ->table([
                                TableColumn::make('Candidate'),
                                TableColumn::make('Change'),
                                TableColumn::make('By'),
                                TableColumn::make('When'),
                            ])
                            ->schema([
                                TextEntry::make('candidate.full_name'),
                                TextEntry::make('change')
                                    ->badge()
                                    ->state(fn (TalentPoolMembership $record): string => $record->isActive() ? 'Added' : 'Removed')
                                    ->color(fn (string $state): string => $state === 'Added' ? 'success' : 'gray'),
                                TextEntry::make('by')->state(fn (TalentPoolMembership $record): string => ($record->isActive() ? $record->addedBy : $record->removedBy)?->fullName() ?? '—'),
                                TextEntry::make('when')->state(fn (TalentPoolMembership $record) => $record->removed_at ?? $record->added_at)->since(),
                            ]),
                    ]),
            ]);
    }
}
