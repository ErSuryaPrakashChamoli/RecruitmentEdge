<?php

namespace App\Filament\Resources\Candidates\Schemas;

use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\Candidate;
use App\Services\CandidateSearchTerm;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "pick a candidate" field for forms whose model has a `candidate` relationship. Names repeat,
 * so each option shows the candidate's mobile number too, and search matches the name, mobile or
 * candidate code.
 */
class CandidatePicker
{
    /**
     * Phase 8.10 (P810-SEC-004): limited to the candidates the current user may see — the options,
     * the search, the selected label and Filament's server-side check of the submitted value all
     * run through selectableCandidates(). Pages that persist the choice re-check it as well.
     */
    public static function make(string $name = 'candidate_id'): Select
    {
        return Select::make($name)
            ->label('Candidate')
            ->relationship('candidate', 'full_name', modifyQueryUsing: fn (Builder $query): Builder => $query->whereIn('candidates.id', self::selectableCandidates()->select('candidates.id')))
            ->getOptionLabelFromRecordUsing(fn (Candidate $record): string => self::label($record))
            ->searchable(['full_name', 'mobile', 'candidate_code'])
            ->preload();
    }

    /**
     * A relationship-free multi-select over the candidates the current user may see
     * (CandidateResource::getEloquentQuery() hierarchy scoping), searched server-side so large
     * candidate tables are never loaded into memory. Callers must still re-check the submitted IDs
     * against selectableCandidates() — option lists are UI, not authorisation.
     */
    public static function multiple(string $name = 'candidate_ids'): Select
    {
        return Select::make($name)
            ->label('Candidates')
            ->multiple()
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => self::searchResults($search))
            ->getOptionLabelsUsing(fn (array $values): array => self::selectableCandidates()
                ->whereKey($values)
                ->get()
                ->mapWithKeys(fn (Candidate $candidate) => [$candidate->id => self::label($candidate)])
                ->all());
    }

    /**
     * Single-choice counterpart of multiple(): relationship-free, server-side searched and limited
     * to candidates the current user may see.
     */
    public static function scoped(string $name = 'candidate_id'): Select
    {
        return Select::make($name)
            ->label('Candidate')
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => self::searchResults($search))
            ->getOptionLabelUsing(fn (mixed $value): ?string => ($candidate = self::selectableCandidates()->find($value)) !== null ? self::label($candidate) : null);
    }

    /**
     * @return array<int, string>
     */
    private static function searchResults(string $search): array
    {
        $candidates = self::selectableCandidates();

        // Phase 8.9 (P89-PERF-003): a complete mobile number or candidate code is an exact, indexed
        // lookup; anything else keeps the substring match (email is not searched here, as before).
        if (! CandidateSearchTerm::applyExact($candidates, $search, email: false)) {
            $candidates->where(fn ($q) => $q->where('full_name', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', "%{$search}%")
                ->orWhere('candidate_code', 'like', "%{$search}%"));
        }

        return $candidates
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Candidate $candidate) => [$candidate->id => self::label($candidate)])
            ->all();
    }

    /**
     * @return Builder<Candidate>
     */
    public static function selectableCandidates(): Builder
    {
        return CandidateResource::getEloquentQuery();
    }

    public static function label(Candidate $candidate): string
    {
        return collect([$candidate->full_name, $candidate->mobile])->filter()->implode(' · ');
    }
}
