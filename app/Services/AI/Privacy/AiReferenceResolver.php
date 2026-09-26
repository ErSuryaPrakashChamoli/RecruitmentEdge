<?php

namespace App\Services\AI\Privacy;

use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Render-time only (Phase 8.1): turns the AI references in AI output back into names for the
 * person viewing it — "CAND-2026-000123" becomes "CAND-2026-000123 — Rahul Sharma" — but only for
 * records that viewer may see under the normal hierarchy rules. Anything else stays a bare code,
 * which reveals nothing. The result is never persisted and never sent back to the provider.
 */
class AiReferenceResolver
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function resolve(?string $text, User $viewer, bool $markdown = false): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $labels = $this->labelsFor($this->referencesIn($text), $viewer);

        if ($labels === []) {
            return $text;
        }

        return (string) preg_replace_callback(AiReference::PATTERN, function (array $match) use ($labels, $markdown): string {
            $name = $labels[$match[0]] ?? null;

            return $name === null ? $match[0] : $match[0].' — '.($markdown ? $this->escapeMarkdown($name) : $name);
        }, $text);
    }

    /**
     * Resolves every string leaf of a structure (e.g. a tool result shown in the UI).
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function resolveStructure(array $data, User $viewer): array
    {
        $references = [];
        array_walk_recursive($data, function (mixed $value) use (&$references): void {
            if (is_string($value)) {
                $references = [...$references, ...$this->referencesIn($value)];
            }
        });

        $labels = $this->labelsFor(array_values(array_unique($references)), $viewer);

        if ($labels === []) {
            return $data;
        }

        array_walk_recursive($data, function (mixed &$value) use ($labels): void {
            if (is_string($value)) {
                $value = (string) preg_replace_callback(AiReference::PATTERN, fn (array $m) => isset($labels[$m[0]]) ? $m[0].' — '.$labels[$m[0]] : $m[0], $value);
            }
        });

        return $data;
    }

    /**
     * @return array<int, string>
     */
    public function referencesIn(string $text): array
    {
        preg_match_all(AiReference::PATTERN, $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @param  array<int, string>  $references
     * @return array<string, string> reference => display name, for visible records only
     */
    public function labelsFor(array $references, User $viewer): array
    {
        if ($references === []) {
            return [];
        }

        $byPrefix = [];

        foreach ($references as $reference) {
            $byPrefix[strtok($reference, '-')][] = $reference;
        }

        $visibleIds = $this->hierarchy->visibleEmployeeIdsFor($viewer);
        $scopeApplications = fn (Builder $query) => $visibleIds === null ? $query : $query->whereIn('recruiter_id', $visibleIds);
        $labels = [];

        if (isset($byPrefix['CAND'])) {
            $labels += Candidate::query()->visibleTo($viewer)->whereIn('candidate_code', $byPrefix['CAND'])->pluck('full_name', 'candidate_code')->all();
        }

        if (isset($byPrefix['APP'])) {
            $scopeApplications(CandidateApplication::query())->whereIn('application_code', $byPrefix['APP'])->with('candidate:id,full_name')->get()
                ->each(function (CandidateApplication $application) use (&$labels): void {
                    $labels[$application->application_code] = (string) $application->candidate?->full_name;
                });
        }

        if (isset($byPrefix['EMP'])) {
            Employee::query()->whereIn('employee_code', $byPrefix['EMP'])
                ->when($visibleIds !== null, fn (Builder $query) => $query->whereIn('id', $visibleIds))
                ->get()
                ->each(function (Employee $employee) use (&$labels): void {
                    $labels[$employee->employee_code] = $employee->fullName();
                });
        }

        if (isset($byPrefix['OFR'])) {
            Offer::query()->whereIn('offer_code', $byPrefix['OFR'])
                ->whereHas('candidateApplication', $scopeApplications)
                ->with('candidateApplication.candidate:id,full_name')->get()
                ->each(function (Offer $offer) use (&$labels): void {
                    $labels[$offer->offer_code] = (string) $offer->candidateApplication?->candidate?->full_name;
                });
        }

        foreach (['INT' => Interview::class, 'JOIN' => CandidateJoining::class] as $prefix => $model) {
            if (! isset($byPrefix[$prefix])) {
                continue;
            }

            $ids = array_map(fn (string $reference) => (int) substr($reference, strlen($prefix) + 1), $byPrefix[$prefix]);

            $model::query()->whereKey($ids)
                ->whereHas('candidateApplication', $scopeApplications)
                ->with('candidateApplication.candidate:id,full_name')->get()
                ->each(function ($record) use (&$labels, $prefix): void {
                    $labels[$prefix.'-'.$record->getKey()] = (string) $record->candidateApplication?->candidate?->full_name;
                });
        }

        return array_filter($labels, fn (?string $name) => filled($name));
    }

    private function escapeMarkdown(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>])/', '\\\\$1', $text);
    }
}
