<?php

namespace App\Filament\Resources\CandidateApplications\Schemas;

use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Models\CandidateApplication;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single "pick an application" field for every form (offers, interviews, follow-ups, daily
 * activities, joinings, incentive calculation). An application code alone isn't recognisable, so
 * each option carries the candidate's name and mobile alongside the codes and stage, and search
 * matches the candidate's name or mobile as well as the code. Options and the validation rule are
 * hierarchy-scoped exactly like the Applications list.
 */
class ApplicationPicker
{
    /**
     * @param  (Closure(Builder<CandidateApplication>): Builder<CandidateApplication>)|null  $modifyOptionsQueryUsing  Narrows only the listed/searchable options (e.g. Active applications), not the validation rule — so editing a record whose application no longer matches still saves.
     */
    public static function make(string $name = 'candidate_application_id', ?Closure $modifyOptionsQueryUsing = null): Select
    {
        return Select::make($name)
            ->label('Application')
            ->helperText('Search by candidate name, mobile number or application code.')
            ->options(fn (): array => self::options($modifyOptionsQueryUsing))
            ->getSearchResultsUsing(fn (string $search): array => self::options($modifyOptionsQueryUsing, $search))
            ->getOptionLabelUsing(fn (mixed $value): ?string => ($application = CandidateApplication::query()->with(['candidate', 'requisition'])->find($value))
                ? self::label($application)
                : null)
            ->searchable()
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && ! self::selectableApplications()->whereKey($value)->exists()) {
                    $fail('The selected application is not available.');
                }
            });
    }

    /**
     * Applications the current user may pick — the same hierarchy scope as the Applications list.
     *
     * @return Builder<CandidateApplication>
     */
    public static function selectableApplications(): Builder
    {
        return CandidateApplicationResource::getEloquentQuery();
    }

    public static function label(CandidateApplication $application): string
    {
        return collect([
            $application->candidate?->full_name,
            $application->candidate?->mobile,
            $application->application_code,
            $application->requisition?->code,
            $application->current_stage->label(),
        ])->filter()->implode(' · ');
    }

    /**
     * @param  (Closure(Builder<CandidateApplication>): Builder<CandidateApplication>)|null  $modifyQueryUsing
     * @return array<int, string>
     */
    private static function options(?Closure $modifyQueryUsing = null, ?string $search = null): array
    {
        return self::selectableApplications()
            ->with(['candidate', 'requisition'])
            ->when($modifyQueryUsing, fn (Builder $query) => $modifyQueryUsing($query))
            ->when(filled($search), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('application_code', 'like', "%{$search}%")
                ->orWhereHas('candidate', fn (Builder $candidate) => $candidate
                    ->where('full_name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%"))))
            ->latest()
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (CandidateApplication $application): array => [$application->id => self::label($application)])
            ->all();
    }
}
