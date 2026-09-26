<?php

namespace App\Filament\Resources\RecruitmentPipelineTemplates\Schemas;

use App\Models\RecruitmentPipelineTemplate;
use App\Models\RecruitmentStage;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RecruitmentPipelineTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Template')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->columnSpanFull(),
                    ]),
                Section::make('Stages')
                    ->description('Drag to reorder. Stages must follow the canonical pipeline order of their "counts as" milestone. Changing this list creates a new template version; requisitions already using the template keep their own copy.')
                    ->schema([
                        Repeater::make('stages')
                            ->hiddenLabel()
                            ->reorderable()
                            ->minItems(1)
                            ->columns(3)
                            ->addActionLabel('Add stage')
                            ->schema([
                                Select::make('recruitment_stage_id')
                                    ->label('Stage')
                                    ->options(fn (?RecruitmentPipelineTemplate $record): array => RecruitmentStage::query()
                                        ->where(fn ($q) => $q->where('is_active', true)
                                            ->when($record !== null, fn ($q) => $q->orWhereIn('id', $record->templateStages()->select('recruitment_stage_id'))))
                                        ->ordered()
                                        ->get()
                                        ->mapWithKeys(fn (RecruitmentStage $stage) => [$stage->id => "{$stage->name} · {$stage->milestone->label()}"])
                                        ->all())
                                    ->searchable()
                                    ->required()
                                    ->distinct(),
                                TextInput::make('sla_hours')
                                    ->label('SLA override (hours)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(8760)
                                    ->placeholder('Inherit'),
                                Select::make('is_skippable')
                                    ->label('Skippable')
                                    ->options(['1' => 'Yes', '0' => 'No'])
                                    ->placeholder('Inherit'),
                            ]),
                    ]),
            ]);
    }
}
