<?php

namespace App\Filament\Resources\RecruitmentSettings\Pages;

use App\Filament\Resources\RecruitmentSettings\RecruitmentSettingResource;
use App\Models\RecruitmentSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Typed, validated editor for the business-rule settings in RecruitmentSetting::DEFINITIONS.
 * Storage is still the generic key/value recruitment_settings table (RecruitmentSetting::put(),
 * whose saved event invalidates each key's cache), so every service keeps reading settings the
 * same way. Access follows the resource (RecruitmentSettingPolicy: `settings.manage`).
 *
 * @property-read Schema $form
 */
class ManageRecruitmentConfiguration extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = RecruitmentSettingResource::class;

    protected string $view = 'filament.resources.recruitment-settings.pages.manage-recruitment-configuration';

    protected static ?string $title = 'Recruitment Configuration';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(
            collect(RecruitmentSetting::DEFINITIONS)
                ->mapWithKeys(fn (array $definition, string $key) => [$key => RecruitmentSetting::get($key, $definition['default'])])
                ->all(),
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Stage SLA targets')
                        ->description('Maximum days allowed for each pipeline leg (SLA / TAT widget and SLA breach alerts).')
                        ->columns(['md' => 2, 'xl' => 4])
                        ->schema([
                            self::daysInput('sla_days_application_to_screening', 'Application → Screening'),
                            self::daysInput('sla_days_shortlist_to_lineup', 'Shortlist → Line-up'),
                            self::daysInput('sla_days_lineup_to_interview', 'Line-up → Interview'),
                            self::daysInput('sla_days_interview_to_selection', 'Interview → Selection'),
                            self::daysInput('sla_days_selection_to_offer', 'Selection → Offer'),
                            self::daysInput('sla_days_offer_to_acceptance', 'Offer → Acceptance'),
                            self::daysInput('sla_days_selection_to_joining', 'Selection → Joining'),
                            self::daysInput('sla_days_time_to_hire_target', 'Time to hire target'),
                        ]),
                    Section::make('Vacancies & time to hire')
                        ->columns(['md' => 2])
                        ->schema([
                            Select::make('time_to_hire_start_point')
                                ->label('Time to hire start point')
                                ->options(RecruitmentSetting::TIME_TO_HIRE_START_POINTS)
                                ->native(false)
                                ->required(),
                            self::daysInput('vacancy_ageing_alert_days', 'Vacancy ageing threshold', 'Open requisitions older than this are flagged as ageing.'),
                            self::daysInput('position_risk_max_days_open', 'Position critical after', 'An unfilled position open longer than this is critical.'),
                            TextInput::make('position_risk_min_pipeline_ratio')
                                ->label('Minimum pipeline per remaining opening')
                                ->numeric()
                                ->minValue(0.1)
                                ->maxValue(50)
                                ->step(0.1)
                                ->required(),
                        ]),
                    Section::make('Pipeline & interviews')
                        ->columns(['md' => 3])
                        ->schema([
                            self::daysInput('candidate_stall_days', 'Candidate stall threshold', 'Active candidates with no stage change for this long are flagged as stalled.'),
                            self::daysInput('offer_expiry_alert_days', 'Offer expiry alert window', 'Released offers expiring within this many days appear in the Action Center.'),
                            TextInput::make('interviewer_daily_capacity')
                                ->label('Interviewer daily capacity')
                                ->helperText('Maximum interviews one interviewer should take per day.')
                                ->integer()
                                ->minValue(1)
                                ->maxValue(50)
                                ->suffix('interviews')
                                ->required(),
                        ]),
                    Section::make('Joining')
                        ->columns(['md' => 2])
                        ->schema([
                            self::daysInput('joining_risk_followup_days', 'Joining risk follow-up window', 'An unconfirmed joining this close to DOJ is marked at risk (yellow).'),
                            self::daysInput('joining_reminder_days', 'Joining reminder', 'Remind the recruiter this many days before the expected DOJ.'),
                        ]),
                    Section::make('Notifications')
                        ->columns(['md' => 2, 'xl' => 3])
                        ->schema([
                            self::integerInput('notification_selected_no_offer_hours', 'Selected without offer alert after', 'hours', 1, 720),
                            self::integerInput('notification_feedback_pending_hours', 'Feedback pending alert after', 'hours', 1, 720),
                            self::daysInput('notification_offer_expiry_warning_days', 'Offer expiry notification window'),
                            self::integerInput('notification_recruiter_shortfall_percent', 'Underperformance threshold', '%', 1, 100),
                            self::integerInput('notification_recruiter_critical_shortfall_percent', 'Critical underperformance threshold', '%', 1, 100),
                        ]),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save configuration')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (RecruitmentSetting::DEFINITIONS as $key => $definition) {
            $value = match ($definition['type']) {
                'int' => (int) $data[$key],
                'float' => (float) $data[$key],
                default => (string) $data[$key],
            };

            RecruitmentSetting::put($key, $value, $definition['type'], $definition['group'], $definition['description']);
        }

        Notification::make()
            ->title('Recruitment configuration saved')
            ->success()
            ->send();
    }

    private static function daysInput(string $key, string $label, ?string $helperText = null): TextInput
    {
        return self::integerInput($key, $label, 'days', 1, 365)->helperText($helperText);
    }

    private static function integerInput(string $key, string $label, string $suffix, int $min, int $max): TextInput
    {
        return TextInput::make($key)
            ->label($label)
            ->integer()
            ->minValue($min)
            ->maxValue($max)
            ->suffix($suffix)
            ->required();
    }
}
