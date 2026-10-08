<?php

namespace App\Filament\Resources\RecruitmentDailyActivities\Schemas;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Filament\Resources\CandidateApplications\Schemas\ApplicationPicker;
use App\Filament\Resources\Candidates\Schemas\CandidatePicker;
use App\Models\Employee;
use App\Services\Metrics\MetricPeriod;
use App\Services\RecruitmentActivityService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class RecruitmentDailyActivityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Phase 8.5 (SEC-4): only yourself or your team; RecruitmentActivityService re-checks.
                Select::make('recruiter_id')
                    ->label('Recruiter')
                    ->relationship('recruiter', 'first_name', fn (Builder $query) => $query->whereIn('employees.id', app(RecruitmentActivityService::class)->recruitersFor(Filament::auth()->user())->select('id')))
                    ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                    ->default(fn () => Filament::auth()->user()?->employee_id)
                    ->required()
                    ->searchable()
                    ->preload(),
                CandidatePicker::make(),
                ApplicationPicker::make(),
                Select::make('activity_type')
                    ->options(collect(ActivityType::cases())->mapWithKeys(fn (ActivityType $t) => [$t->value => $t->label()]))
                    ->required(),
                DateTimePicker::make('activity_datetime')
                    ->default(now())
                    ->maxDate(now())
                    ->minDate(fn () => MetricPeriod::now()->subDays(RecruitmentActivityService::backdateDays())->startOfDay())
                    ->helperText(fn () => 'Up to '.RecruitmentActivityService::backdateDays().' day(s) back.')
                    ->required(),
                Select::make('outcome')
                    ->options(collect(ActivityOutcome::cases())->mapWithKeys(fn (ActivityOutcome $o) => [$o->value => $o->label()])),
                Textarea::make('remarks')
                    ->columnSpanFull(),
            ]);
    }
}
