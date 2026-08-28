<?php

namespace App\Services;

use App\Enums\DuplicateMatchType;
use App\Models\Candidate;
use Illuminate\Support\Collection;

/**
 * Flags likely-duplicate candidates without ever blocking creation (Section 10: a candidate must
 * still be able to apply to another requisition even if they already exist). Matches are logged
 * for HR to review, not auto-merged.
 */
class CandidateDuplicateDetector
{
    /**
     * @return Collection<int, array{candidate: Candidate, type: DuplicateMatchType}>
     */
    public function findMatches(Candidate $candidate): Collection
    {
        $matches = collect();

        Candidate::query()
            ->whereKeyNot($candidate->id)
            ->where('mobile', $candidate->mobile)
            ->get()
            ->each(fn (Candidate $match) => $matches->push(['candidate' => $match, 'type' => DuplicateMatchType::Mobile]));

        if (filled($candidate->email)) {
            Candidate::query()
                ->whereKeyNot($candidate->id)
                ->where('email', $candidate->email)
                ->get()
                ->each(fn (Candidate $match) => $matches->push(['candidate' => $match, 'type' => DuplicateMatchType::Email]));
        }

        return $matches->unique(fn (array $match) => $match['candidate']->id.':'.$match['type']->value);
    }
}
