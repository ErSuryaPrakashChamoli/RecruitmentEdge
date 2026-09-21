<?php

namespace App\Filament\Resources\RecruitmentIncentiveRules\Schemas;

use App\Enums\EmploymentType;
use App\Enums\IncentivePayoutType;
use App\Enums\IncentiveSlabUpgradeMode;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\TargetMetric;
use App\Models\Employee;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class RecruitmentIncentiveRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Section::make('Trigger')
                    ->columns(2)
                    ->schema([
                        Select::make('trigger_event')
                            ->options(collect(IncentiveTriggerEvent::cases())->mapWithKeys(fn (IncentiveTriggerEvent $e) => [$e->value => $e->label()]))
                            ->required()
                            ->helperText('Only "On Joining" rules are calculated automatically today; other triggers require the manual "Calculate Incentives" action.'),
                        TextInput::make('retention_days')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Leave blank to skip a retention hold. If set, the calculation stays in "Calculated" until this many days after the trigger event.'),
                    ]),
                Section::make('Payout')
                    ->description('How much each occurrence of the trigger (for example, each joining) pays. Slab rates are added in the Slabs table below once the rule is saved.')
                    ->columns(2)
                    ->schema([
                        Radio::make('payout_type')
                            ->label('Payout type')
                            ->options(collect(IncentivePayoutType::cases())->mapWithKeys(fn (IncentivePayoutType $type) => [$type->value => $type->label()]))
                            ->descriptions(collect(IncentivePayoutType::cases())->mapWithKeys(fn (IncentivePayoutType $type) => [$type->value => $type->description()]))
                            ->default(IncentivePayoutType::SlabByAchievement->value)
                            ->required()
                            ->live(),
                        Radio::make('slab_upgrade_mode')
                            ->label('When a higher slab is reached, pay the higher rate on')
                            ->options(collect(IncentiveSlabUpgradeMode::cases())->mapWithKeys(fn (IncentiveSlabUpgradeMode $mode) => [$mode->value => $mode->label()]))
                            ->descriptions(collect(IncentiveSlabUpgradeMode::cases())->mapWithKeys(fn (IncentiveSlabUpgradeMode $mode) => [$mode->value => $mode->description()]))
                            ->default(IncentiveSlabUpgradeMode::Incremental->value)
                            ->required(fn (Get $get): bool => self::payoutType($get)?->usesSlabs() ?? false)
                            ->visible(fn (Get $get): bool => self::payoutType($get)?->usesSlabs() ?? false),
                        TextInput::make('fixed_amount')
                            ->label('Amount per occurrence')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0.01)
                            ->required(fn (Get $get): bool => self::payoutType($get) === IncentivePayoutType::Fixed)
                            ->visible(fn (Get $get): bool => self::payoutType($get) === IncentivePayoutType::Fixed),
                        Select::make('achievement_metric')
                            ->label('Achievement metric')
                            ->options(collect(TargetMetric::cases())->mapWithKeys(fn (TargetMetric $m) => [$m->value => $m->label()]))
                            ->helperText('Slabs are matched against the recruiter\'s achievement % on this metric for the month.')
                            ->required(fn (Get $get): bool => self::payoutType($get) === IncentivePayoutType::SlabByAchievement)
                            ->visible(fn (Get $get): bool => self::payoutType($get) === IncentivePayoutType::SlabByAchievement),
                    ]),
                Section::make('Scope')
                    ->description('Leave every field blank to apply this rule to everyone.')
                    ->columns(3)
                    ->schema([
                        Select::make('employee_id')
                            ->label('Recruiter')
                            ->relationship('employee', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                            ->searchable()
                            ->preload(),
                        Select::make('department_id')
                            ->relationship('department', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('designation_id')
                            ->relationship('designation', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('location_id')
                            ->relationship('location', 'name')
                            ->helperText('Matched against the recruiter\'s own location.')
                            ->searchable()
                            ->preload(),
                        Select::make('employment_type')
                            ->options(collect(EmploymentType::cases())->mapWithKeys(fn (EmploymentType $t) => [$t->value => $t->label()])),
                    ]),
                Section::make('Effective Period')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('effective_from')
                            ->default(now())
                            ->required(),
                        DatePicker::make('effective_to'),
                    ]),
                Toggle::make('is_active')
                    ->default(true)
                    ->required(),
            ]);
    }

    /**
     * The form state holds either the enum (a loaded record) or its raw value (a user's choice).
     */
    private static function payoutType(Get $get): ?IncentivePayoutType
    {
        $state = $get('payout_type');

        return $state instanceof IncentivePayoutType ? $state : IncentivePayoutType::tryFrom((string) $state);
    }
}
