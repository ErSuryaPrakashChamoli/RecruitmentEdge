<?php

namespace App\Filament\Resources\RecruitmentStages\Schemas;

use App\Enums\StageRequirement;
use App\Models\RecruitmentStage;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RecruitmentStageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Stage')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')
                            ->badge()
                            ->color(fn (RecruitmentStage $record): string => $record->color),
                        TextEntry::make('code'),
                        TextEntry::make('stage_type')->formatStateUsing(fn ($state) => $state->label()),
                        TextEntry::make('milestone')->label('Counts as')->formatStateUsing(fn ($state) => $state->label()),
                        TextEntry::make('sla_hours')->label('SLA (hours)')->placeholder('No SLA'),
                        IconEntry::make('is_active')->label('Active')->boolean(),
                        TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Behaviour')
                    ->columns(4)
                    ->schema([
                        IconEntry::make('requires_recruiter_action')->boolean(),
                        IconEntry::make('requires_candidate_action')->boolean(),
                        IconEntry::make('is_interview_stage')->label('Interview')->boolean(),
                        IconEntry::make('is_offer_stage')->label('Offer')->boolean(),
                        IconEntry::make('is_joining_stage')->label('Joining')->boolean(),
                        IconEntry::make('is_skippable')->label('Skippable')->boolean(),
                        IconEntry::make('is_terminal')->label('Terminal')->boolean(),
                        IconEntry::make('allows_rejection')->boolean(),
                        TextEntry::make('requirements')
                            ->label('Required before entering')
                            ->formatStateUsing(fn (string $state): string => StageRequirement::tryFrom($state)?->label() ?? $state)
                            ->badge()
                            ->placeholder('None')
                            ->columnSpanFull(),
                        TextEntry::make('allowedNextStages.name')
                            ->label('Allowed next stages')
                            ->badge()
                            ->placeholder('Default forward rule')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
