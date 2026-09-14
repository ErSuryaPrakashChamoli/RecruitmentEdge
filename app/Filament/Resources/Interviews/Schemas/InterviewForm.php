<?php

namespace App\Filament\Resources\Interviews\Schemas;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewMode;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class InterviewForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('candidate_application_id')
                    ->label('Application')
                    ->relationship('candidateApplication', 'application_code', fn (Builder $query) => self::scopeApplicationsToViewer($query))
                    ->required()
                    ->searchable()
                    ->preload(),
                ...self::schedulingFields(),
            ]);
    }

    /**
     * The fields every "schedule an interview" surface shares (create page, Candidate 360, the
     * application's Interviews tab, the Interview Calendar) — all of which hand the resulting data
     * to InterviewService::schedule().
     *
     * @param  CandidateApplication|null  $application  When known, the round number defaults to the next round.
     * @return array<int, Component>
     */
    public static function schedulingFields(?CandidateApplication $application = null): array
    {
        return [
            TextInput::make('round_number')
                ->numeric()
                ->minValue(1)
                ->default(fn (): ?int => $application !== null ? $application->interviews()->count() + 1 : null)
                ->placeholder('Next round')
                ->helperText('Leave blank to use the next round number.')
                ->required(fn (?string $operation): bool => $operation === 'edit'),
            TextInput::make('round_name')
                ->maxLength(255),
            Select::make('interviewer_id')
                ->label('Interviewer')
                ->options(fn () => Employee::query()->get()->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->fullName()]))
                ->required()
                ->searchable(),
            DateTimePicker::make('scheduled_at')
                ->required(),
            Select::make('mode')
                ->options(collect(InterviewMode::cases())->mapWithKeys(fn (InterviewMode $m) => [$m->value => $m->label()]))
                ->required(),
            TextInput::make('location')
                ->maxLength(255),
            TextInput::make('meeting_link')
                ->label('Meeting Link')
                ->url()
                ->maxLength(500),
            Textarea::make('remarks')
                ->columnSpanFull(),
        ];
    }

    /**
     * Active applications the current user may see (Section 27 hierarchy scoping) — used wherever
     * an application has to be picked before scheduling.
     */
    public static function applicationSelect(): Select
    {
        return Select::make('candidate_application_id')
            ->label('Application')
            ->options(fn () => self::scopeApplicationsToViewer(CandidateApplication::query())
                ->where('status', ApplicationStatus::Active)
                ->with('candidate')
                ->orderByDesc('id')
                ->get()
                ->mapWithKeys(fn (CandidateApplication $application) => [
                    $application->id => "{$application->application_code} — {$application->candidate?->full_name}",
                ]))
            ->searchable()
            ->required();
    }

    /**
     * @param  Builder<CandidateApplication>  $query
     * @return Builder<CandidateApplication>
     */
    public static function scopeApplicationsToViewer(Builder $query): Builder
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        $visibleIds = $user !== null ? app(HierarchyService::class)->visibleEmployeeIdsFor($user) : collect();

        return $query->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds));
    }
}
