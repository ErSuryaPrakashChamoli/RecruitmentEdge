<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Filament\Concerns\HasSavedTableViews;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Services\CandidateSearchTerm;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCandidates extends ListRecords
{
    use HasSavedTableViews;

    protected static string $resource = CandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedTableViewActions(),
            CreateAction::make(),
        ];
    }

    /**
     * Phase 8.9 (P89-PERF-003): a complete email, mobile number or candidate code is an exact,
     * indexed lookup (CandidateSearchTerm); any other term keeps the table's substring search.
     */
    protected function applyGlobalSearchToTableQuery(Builder $query): Builder
    {
        $search = $this->getTableSearch();

        if (filled($search) && CandidateSearchTerm::applyExact($query, $search)) {
            return $query;
        }

        return parent::applyGlobalSearchToTableQuery($query);
    }
}
