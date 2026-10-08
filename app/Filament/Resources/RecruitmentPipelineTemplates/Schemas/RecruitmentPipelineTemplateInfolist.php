<?php

namespace App\Filament\Resources\RecruitmentPipelineTemplates\Schemas;

use App\Models\RecruitmentPipelineTemplateStage;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RecruitmentPipelineTemplateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Template')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('version')->prefix('v'),
                        IconEntry::make('is_default')->label('Default')->boolean(),
                        IconEntry::make('is_active')->label('Active')->boolean(),
                        TextEntry::make('clonedFrom.name')->label('Cloned from')->placeholder('—'),
                        TextEntry::make('requisitions_count')->label('Requisitions using it')->state(fn ($record) => $record->requisitions()->count()),
                        TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Stages')
                    ->schema([
                        RepeatableEntry::make('templateStages')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Stage'),
                                TableColumn::make('Counts as'),
                                TableColumn::make('SLA'),
                                TableColumn::make('Skippable'),
                            ])
                            ->schema([
                                TextEntry::make('stage.name')
                                    ->badge()
                                    ->color(fn (RecruitmentPipelineTemplateStage $record): string => $record->stage->color),
                                TextEntry::make('stage.milestone')->formatStateUsing(fn ($state) => $state->label()),
                                TextEntry::make('sla')
                                    ->state(fn (RecruitmentPipelineTemplateStage $record): string => ($hours = $record->sla_hours ?? $record->stage->sla_hours) !== null ? "{$hours} h" : '—'),
                                IconEntry::make('skippable')
                                    ->state(fn (RecruitmentPipelineTemplateStage $record): bool => $record->is_skippable ?? $record->stage->is_skippable)
                                    ->boolean(),
                            ]),
                    ]),
            ]);
    }
}
