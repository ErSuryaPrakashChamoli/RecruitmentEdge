<?php

namespace App\Filament\Resources\RecruitmentStages\Schemas;

use App\Enums\CandidateStage;
use App\Enums\StageRequirement;
use App\Enums\StageType;
use App\Models\RecruitmentStage;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RecruitmentStageForm
{
    /**
     * Semantic colours only — stage badges follow the panel's theme tokens rather than hard-coded
     * hues, so every theme keeps working.
     *
     * @var array<string, string>
     */
    public const array COLORS = [
        'gray' => 'Neutral',
        'info' => 'Info',
        'primary' => 'Brand',
        'warning' => 'Attention',
        'success' => 'Success',
        'danger' => 'Danger',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Stage')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->helperText('Stable identifier. Generated from the name when left blank; cannot change later.')
                            ->maxLength(60)
                            ->alphaDash()
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                        Select::make('stage_type')
                            ->label('Stage type')
                            ->options(StageType::options())
                            ->required()
                            ->default(StageType::Custom->value),
                        Select::make('milestone')
                            ->label('Counts as (analytics milestone)')
                            ->helperText('The canonical pipeline stage this stage reports as in funnels, SLA and incentives.')
                            ->options(collect(CandidateStage::cases())->mapWithKeys(fn (CandidateStage $s) => [$s->value => $s->label()])->all())
                            ->required()
                            ->disabled(fn (?RecruitmentStage $record): bool => (bool) $record?->is_system),
                        Select::make('color')
                            ->options(self::COLORS)
                            ->default('gray')
                            ->required(),
                        TextInput::make('icon')
                            ->placeholder('heroicon-o-flag')
                            ->helperText('Optional Heroicon name; defaults to the stage type icon.')
                            ->maxLength(100),
                        Textarea::make('description')
                            ->columnSpanFull(),
                    ]),
                Section::make('Behaviour')
                    ->columns(3)
                    ->schema([
                        TextInput::make('sla_hours')
                            ->label('SLA (hours)')
                            ->helperText('How long an application may sit in this stage before it breaches SLA.')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(8760),
                        Toggle::make('requires_recruiter_action')->default(true),
                        Toggle::make('requires_candidate_action'),
                        Toggle::make('is_interview_stage')->label('Interview associated'),
                        Toggle::make('is_offer_stage')->label('Offer associated'),
                        Toggle::make('is_joining_stage')->label('Joining associated'),
                        Toggle::make('is_skippable')->label('Can be skipped')->default(true),
                        Toggle::make('is_terminal')->label('Terminal (no further moves)'),
                        Toggle::make('allows_rejection')->label('Rejection allowed here')->default(true),
                        Toggle::make('allows_dropout')->label('Dropout allowed here')->default(true),
                        CheckboxList::make('requirements')
                            ->label('Required before entering')
                            ->options(StageRequirement::options())
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Allowed transitions')
                    ->description('Leave empty for the default rule: any later stage, without skipping a stage that cannot be skipped.')
                    ->schema([
                        Repeater::make('transitions')
                            ->hiddenLabel()
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add allowed next stage')
                            ->schema([
                                Select::make('to_stage_id')
                                    ->label('Next stage')
                                    ->options(fn (?RecruitmentStage $record): array => RecruitmentStage::query()
                                        ->when($record !== null, fn ($q) => $q->whereKeyNot($record->id))
                                        ->ordered()
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->searchable()
                                    ->required()
                                    ->distinct(),
                                Toggle::make('requires_remarks')->inline(false),
                            ]),
                    ]),
                Section::make('Candidate visibility')
                    ->columns(2)
                    ->schema([
                        Toggle::make('candidate_visible')
                            ->label('Show this stage to candidates in the portal')
                            ->default(true),
                        TextInput::make('candidate_label')
                            ->label('Candidate-facing label')
                            ->helperText('What the candidate sees instead of the internal stage name.')
                            ->maxLength(255),
                    ]),
            ]);
    }
}
