<?php

namespace App\Filament\Resources\Interviews\Schemas;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewMode;
use App\Enums\InterviewRoundName;
use App\Enums\InterviewRoundNumber;
use App\Filament\Resources\CandidateApplications\Schemas\ApplicationPicker;
use App\Models\CandidateApplication;
use App\Models\Interview;
use App\Models\Interviewer;
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
use Illuminate\Database\Eloquent\Model;

class InterviewForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::applicationSelect(),
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
            Select::make('round_number')
                ->options(collect(InterviewRoundNumber::cases())->mapWithKeys(fn (InterviewRoundNumber $round) => [$round->value => $round->label()]))
                ->default(fn (): ?int => $application !== null ? InterviewRoundNumber::tryFrom($application->interviews()->count() + 1)?->value : null)
                ->placeholder('Next round')
                ->helperText('Leave blank to use the next round number.')
                ->required(fn (?string $operation): bool => $operation === 'edit'),
            Select::make('round_name')
                ->options(collect(InterviewRoundName::cases())->mapWithKeys(fn (InterviewRoundName $round) => [$round->value => $round->label()])),
            Select::make('interviewer_id')
                ->label('Interviewer')
                ->options(fn (?Model $record): array => Interviewer::selectOptions($record instanceof Interview ? $record->interviewer_id : null))
                ->helperText('Only employees on the interviewer list (Administration → Interviewers) are shown.')
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
     * Hierarchy-scoped application picker (Section 27) used wherever an application has to be picked
     * before scheduling. Only Active applications are offered, since InterviewService::schedule()
     * refuses any other status.
     */
    public static function applicationSelect(): Select
    {
        return ApplicationPicker::make(modifyOptionsQueryUsing: fn (Builder $query): Builder => $query->where('status', ApplicationStatus::Active))
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
