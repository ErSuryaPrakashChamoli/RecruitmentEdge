<?php

namespace App\Filament\Resources\AutomationRules\Schemas;

use App\Enums\ActionPriority;
use App\Enums\AutomationFailureBehavior;
use App\Enums\AutomationScope;
use App\Enums\CommunicationChannel;
use App\Enums\FollowupType;
use App\Enums\RecruiterActionType;
use App\Enums\TemplateStatus;
use App\Filament\Resources\RecruiterActions\Tables\RecruiterActionsTable;
use App\Models\CommunicationTemplate;
use App\Models\Department;
use App\Models\Location;
use App\Models\RecruitmentRequisition;
use App\Services\Automation\Actions\Handlers\MoveStageAction;
use App\Services\Automation\Actions\Handlers\SendCommunicationAction;
use App\Services\Automation\AutomationActionRegistry;
use App\Services\Automation\AutomationEventRegistry;
use App\Services\Automation\AutomationFieldRegistry;
use App\Services\Automation\AutomationTime;
use App\Services\Automation\RecipientResolver;
use App\Services\Communication\TemplateRenderer;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The visual rule builder (Phase 6): WHEN (trigger + timing) → IF (conditions with ALL/ANY/NOT
 * and one level of nested groups) → THEN (action blocks) → ESCALATE (hierarchy steps + stop
 * condition). Every choice comes from the registries — there is no free-form expression or code.
 * AutomationRuleFormData maps this state to the stored configuration.
 */
class AutomationRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        $events = app(AutomationEventRegistry::class);

        return $schema->columns(1)->components([
            // Phase 8.6 (D8.6-021): a change to what an existing rule does is saved as a new version
            // with this reason as its summary.
            Section::make('Reason for this change')
                ->visibleOn('edit')
                ->schema([
                    Textarea::make('change_reason')
                        ->label('Reason')
                        ->helperText('Required when the change affects what the rule does. Recorded on the new version and in the audit log.')
                        ->maxLength(1000)
                        ->dehydrated(),
                ]),
            Section::make('Rule')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(200),
                    TextInput::make('priority')->numeric()->minValue(1)->maxValue(1000)->default(100)->helperText('Lower numbers run first when several rules share a trigger.'),
                    Textarea::make('description')->rows(2)->columnSpanFull(),
                    Select::make('scope_type')
                        ->label('Applies to')
                        ->options(AutomationScope::options())
                        ->default(AutomationScope::Organization->value)
                        ->required()
                        ->live(),
                    Select::make('scope_id')
                        ->label(fn (Get $get) => AutomationScope::tryFrom((string) $get('scope_type'))?->label() ?? 'Scope')
                        ->options(fn (Get $get): array => self::scopeOptions((string) $get('scope_type')))
                        ->searchable()
                        ->required(fn (Get $get) => $get('scope_type') !== AutomationScope::Organization->value)
                        ->visible(fn (Get $get) => filled($get('scope_type')) && $get('scope_type') !== AutomationScope::Organization->value),
                ]),

            Section::make('WHEN')
                ->description('What starts this automation.')
                ->columns(4)
                ->schema([
                    Select::make('trigger')
                        ->options($events->options())
                        ->required()
                        ->searchable()
                        ->live()
                        ->helperText(fn (Get $get): ?string => $events->find((string) $get('trigger'))?->description)
                        ->columnSpanFull(),

                    // Event triggers: run now, or after a delay measured from the event or a date.
                    Select::make('timing.mode')
                        ->label('Run')
                        ->options(['immediate' => 'Immediately', 'delay' => 'After a delay'])
                        ->default('immediate')
                        ->live()
                        ->visible(fn (Get $get) => self::isEventTrigger($get)),
                    TextInput::make('timing.amount')
                        ->label(fn (Get $get) => self::isScheduleTrigger($get) ? ($events->find((string) $get('trigger'))?->thresholdLabel ?? 'Threshold') : 'Delay')
                        ->numeric()
                        ->minValue(0)
                        ->required(fn (Get $get) => self::isScheduleTrigger($get) || $get('timing.mode') === 'delay')
                        ->visible(fn (Get $get) => self::isScheduleTrigger($get) || (self::isEventTrigger($get) && $get('timing.mode') === 'delay')),
                    Select::make('timing.unit')
                        ->label('Unit')
                        ->options(AutomationTime::UNITS)
                        ->default('hours')
                        ->visible(fn (Get $get) => self::isScheduleTrigger($get) || (self::isEventTrigger($get) && $get('timing.mode') === 'delay')),
                    Select::make('timing.direction')
                        ->label('Before / after')
                        ->options(['after' => 'After', 'before' => 'Before'])
                        ->default('after')
                        ->visible(fn (Get $get) => self::isEventTrigger($get) && $get('timing.mode') === 'delay'),
                    Select::make('timing.anchor')
                        ->label('Measured from')
                        ->options(fn (Get $get): array => $events->find((string) $get('trigger'))?->anchors ?? ['event' => 'When the event happens'])
                        ->default('event')
                        ->visible(fn (Get $get) => self::isEventTrigger($get) && $get('timing.mode') === 'delay'),
                    TextInput::make('timing.repeat_every_hours')
                        ->label('Repeat every (hours)')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Leave empty to run once per record (and again only if its date changes).')
                        ->visible(fn (Get $get) => self::isScheduleTrigger($get)),
                ]),

            Section::make('IF')
                ->description('Only continue when these conditions hold. Leave empty to always continue.')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('condition_match')->label('Match')->options(['all' => 'ALL of the conditions (AND)', 'any' => 'ANY of the conditions (OR)'])->default('all')->required(),
                        Toggle::make('condition_negate')->label('NOT — continue only when this does not match')->inline(false),
                    ]),
                    self::conditionRepeater('condition_rules', 'Add condition'),
                    Repeater::make('condition_groups')
                        ->label('Condition groups')
                        ->helperText('A group is combined with the conditions above as one more condition — e.g. ALL of (…) AND ANY of (…).')
                        ->addActionLabel('Add condition group')
                        ->collapsible()
                        ->defaultItems(0)
                        ->schema([
                            Grid::make(2)->schema([
                                Select::make('match')->options(['all' => 'ALL (AND)', 'any' => 'ANY (OR)'])->default('any')->required(),
                                Toggle::make('negate')->label('NOT')->inline(false),
                            ]),
                            self::conditionRepeater('rules', 'Add condition to group'),
                        ]),
                ]),

            Section::make('THEN')
                ->description('What the automation does, in order.')
                ->schema([
                    Builder::make('actions_builder')
                        ->hiddenLabel()
                        ->addActionLabel('Add action')
                        ->collapsible()
                        ->blocks(self::actionBlocks())
                        ->maxItems(10),
                    Select::make('failure_behavior')
                        ->label('If an action fails')
                        ->options(AutomationFailureBehavior::options())
                        ->default(AutomationFailureBehavior::Continue->value)
                        ->required(),
                ]),

            Section::make('ESCALATE')
                ->description('If the issue is still unresolved, escalate up the recruiter\'s reporting line. Escalation stops as soon as the stop condition holds or the rule\'s Action Center item is closed.')
                ->collapsible()
                ->schema([
                    Repeater::make('escalation_steps')
                        ->label('Steps')
                        ->addActionLabel('Add escalation step')
                        ->defaultItems(0)
                        ->maxItems(5)
                        ->columns(4)
                        ->schema([
                            TextInput::make('after')->label('After')->numeric()->minValue(0)->required(),
                            Select::make('unit')->options(AutomationTime::UNITS)->default('hours')->required(),
                            Select::make('target')->label('Escalate to')->options(collect(RecipientResolver::ESCALATION_TARGETS)->mapWithKeys(fn (string $t) => [$t => RecipientResolver::label($t)])->all())->required(),
                            Select::make('priority')->options(ActionPriority::options())->default(ActionPriority::High->value)->required(),
                            Toggle::make('create_action')->label('Also create an action for them')->inline(false)->columnSpan(2),
                            Select::make('candidate_template')->label('Also message the candidate (optional)')->options(fn () => self::templateOptions())->columnSpan(2),
                            Textarea::make('message')->label('Message to the escalation recipient (optional)')->rows(2)->columnSpanFull(),
                        ]),
                    Select::make('stop_match')->label('Stop when')->options(['any' => 'ANY of these is true', 'all' => 'ALL of these are true'])->default('any'),
                    self::conditionRepeater('stop_rules', 'Add stop condition'),
                ]),

            Section::make('Safety limits')
                ->columns(3)
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextInput::make('cooldown_minutes')->label('Cooldown (minutes)')->numeric()->minValue(0)->helperText('Do not run again for the same record within this time.'),
                    TextInput::make('max_executions_per_day')->label('Max runs per day')->numeric()->minValue(1),
                    TextInput::make('max_executions_per_entity')->label('Max runs per record')->numeric()->minValue(1),
                    DateTimePicker::make('effective_from')->seconds(false),
                    DateTimePicker::make('effective_until')->seconds(false)->after('effective_from'),
                ]),
        ]);
    }

    public static function conditionRepeater(string $name, string $addLabel): Repeater
    {
        $fields = app(AutomationFieldRegistry::class);
        $type = fn (Get $get): ?string => $fields->find((string) $get('field'))?->type;
        $operator = fn (Get $get): string => (string) $get('operator');
        $isSingle = fn (Get $get): bool => filled($get('operator')) && ! in_array($operator($get), [...AutomationFieldRegistry::UNARY_OPERATORS, ...AutomationFieldRegistry::DURATION_OPERATORS, 'in', 'not_in', 'before', 'after'], true);

        return Repeater::make($name)
            ->hiddenLabel()
            ->addActionLabel($addLabel)
            ->defaultItems(0)
            ->columns(4)
            ->schema([
                Select::make('field')->options($fields->options())->searchable()->required()->live()->columnSpan(2),
                Select::make('operator')->options(fn (Get $get) => $fields->operatorsFor($get('field')))->required()->live(),
                Select::make('choice')->label('Value')->options(fn (Get $get) => $fields->find((string) $get('field'))?->options() ?? [])->searchable()->required()
                    ->visible(fn (Get $get) => $type($get) === 'enum' && $isSingle($get)),
                Select::make('flag')->label('Value')->options(['1' => 'Yes', '0' => 'No'])->default('1')->required()
                    ->visible(fn (Get $get) => $type($get) === 'boolean'),
                TextInput::make('value')->label('Value')->required()
                    ->numeric(fn (Get $get) => $type($get) === 'number')
                    ->visible(fn (Get $get) => in_array($type($get), ['string', 'number'], true) && $isSingle($get)),
                Select::make('values')->label('Values')->multiple()->options(fn (Get $get) => $fields->find((string) $get('field'))?->options() ?? [])->required()
                    ->visible(fn (Get $get) => $type($get) === 'enum' && in_array($operator($get), ['in', 'not_in'], true)),
                TagsInput::make('values')->label('Values')->required()
                    ->visible(fn (Get $get) => $type($get) === 'string' && in_array($operator($get), ['in', 'not_in'], true)),
                TextInput::make('amount')->label('Amount')->numeric()->minValue(0)->required()
                    ->visible(fn (Get $get) => in_array($operator($get), AutomationFieldRegistry::DURATION_OPERATORS, true)),
                Select::make('unit')->options(AutomationTime::UNITS)->default('hours')->required()
                    ->visible(fn (Get $get) => in_array($operator($get), AutomationFieldRegistry::DURATION_OPERATORS, true)),
                DateTimePicker::make('date')->label('Date')->seconds(false)->required()
                    ->visible(fn (Get $get) => in_array($operator($get), ['before', 'after'], true)),
            ]);
    }

    /**
     * @return array<int, Block>
     */
    private static function actionBlocks(): array
    {
        $registry = app(AutomationActionRegistry::class);
        $label = fn (string $key) => fn (?array $state): string => $state === null ? $registry->find($key)->label() : $registry->find($key)->describe($state);
        $targets = RecipientResolver::TARGETS;
        $variables = 'You may use variables such as {{candidate.name}}, {{requisition.title}} or {{interview.date}} — '.implode(', ', array_map(fn ($v) => '{{'.$v.'}}', array_keys(TemplateRenderer::VARIABLES))).'.';

        return [
            Block::make('send_communication')->label($label('send_communication'))->icon('heroicon-o-envelope')->columns(2)->schema([
                Select::make('template_key')->label('Message template')->options(fn () => self::templateOptions())->required()->searchable(),
                CheckboxList::make('channels')->options(CommunicationChannel::options(sendableOnly: true))->helperText('Leave empty to use every channel with an active template. Candidate preferences always apply.'),
            ]),
            Block::make('notify')->label($label('notify'))->icon('heroicon-o-bell')->columns(2)->schema([
                Select::make('recipient')->options($targets)->required(),
                Select::make('priority')->options(ActionPriority::options())->default(ActionPriority::Medium->value)->required(),
                TextInput::make('title')->required()->maxLength(200)->columnSpanFull()->helperText($variables),
                Textarea::make('message')->rows(2)->columnSpanFull(),
            ]),
            Block::make('create_action')->label($label('create_action'))->icon('heroicon-o-bolt')->columns(2)->schema([
                Select::make('action_type')->label('Action')->options(RecruiterActionType::options())->required(),
                Select::make('owner')->options($targets)->default('recruiter')->required(),
                Select::make('priority')->options(ActionPriority::options())->default(ActionPriority::Medium->value)->required(),
                TextInput::make('due_in_hours')->label('Due in (hours)')->numeric()->minValue(0),
                TextInput::make('title')->maxLength(200)->columnSpanFull()->helperText($variables),
                TextInput::make('suggested_action')->label('Suggested step')->maxLength(250)->columnSpanFull(),
            ]),
            Block::make('create_followup')->label($label('create_followup'))->icon('heroicon-o-phone')->columns(2)->schema([
                Select::make('followup_type')->options(collect(FollowupType::cases())->mapWithKeys(fn (FollowupType $t) => [$t->value => $t->label()])->all())->required(),
                TextInput::make('due_in_hours')->label('Due in (hours)')->numeric()->minValue(0)->required(),
                TextInput::make('remarks')->columnSpanFull(),
            ]),
            Block::make('escalate')->label($label('escalate'))->icon('heroicon-o-arrow-trending-up')->columns(2)->schema([
                Select::make('target')->label('Escalate to')->options(collect(RecipientResolver::ESCALATION_TARGETS)->mapWithKeys(fn (string $t) => [$t => RecipientResolver::label($t)])->all())->required(),
                Select::make('priority')->options(ActionPriority::options())->default(ActionPriority::High->value)->required(),
                Toggle::make('create_action')->label('Also create an action for them'),
                Textarea::make('message')->rows(2)->columnSpanFull(),
            ]),
            Block::make('move_stage')->label($label('move_stage'))->icon('heroicon-o-arrow-right')->schema([
                Select::make('stage')->options(collect(MoveStageAction::ALLOWED_STAGES)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all())->required()
                    ->helperText('Only early, non-decision stages. Selection, offers, joining and rejection always stay with people.'),
            ]),
            Block::make('hold_application')->label($label('hold_application'))->icon('heroicon-o-pause')->schema([
                TextInput::make('remarks')->required(),
            ]),
            Block::make('add_timeline_event')->label($label('add_timeline_event'))->icon('heroicon-o-clock')->schema([
                TextInput::make('title')->required(),
                Textarea::make('description')->rows(2),
            ]),
            Block::make('add_audit_event')->label($label('add_audit_event'))->icon('heroicon-o-document-magnifying-glass')->schema([
                TextInput::make('note')->required(),
            ]),
        ];
    }

    /**
     * Template keys an automation may send (Phase 5 automatic ones are reserved).
     *
     * @return array<string, string>
     */
    public static function templateOptions(): array
    {
        return CommunicationTemplate::query()
            ->whereNotIn('key', SendCommunicationAction::reservedTemplateKeys())
            ->where('status', '!=', TemplateStatus::Archived)
            ->orderBy('name')
            ->get(['key', 'name', 'status'])
            ->unique('key')
            ->mapWithKeys(fn (CommunicationTemplate $template) => [$template->key => $template->name.($template->status === TemplateStatus::Active ? '' : ' (draft)')])
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    private static function scopeOptions(string $scope): array
    {
        return match (AutomationScope::tryFrom($scope)) {
            AutomationScope::Department => Department::query()->orderBy('name')->pluck('name', 'id')->all(),
            AutomationScope::Location => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            AutomationScope::Requisition => RecruitmentRequisition::query()->latest('id')->limit(200)->pluck('code', 'id')->all(),
            AutomationScope::Recruiter, AutomationScope::Team => RecruiterActionsTable::teamOptions(),
            default => [],
        };
    }

    private static function isScheduleTrigger(Get $get): bool
    {
        return app(AutomationEventRegistry::class)->find((string) $get('trigger'))?->isScheduled() === true;
    }

    private static function isEventTrigger(Get $get): bool
    {
        $definition = app(AutomationEventRegistry::class)->find((string) $get('trigger'));

        return $definition !== null && ! $definition->isScheduled();
    }
}
