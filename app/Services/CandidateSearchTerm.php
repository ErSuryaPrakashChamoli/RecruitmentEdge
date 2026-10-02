<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 8.9 (P89-PERF-003): a candidate search for a *complete* identifier — a full email address,
 * a full 10-digit mobile number or a full candidate code — is an exact lookup on the indexed
 * normalized identity columns (the duplicate detector's columns) instead of a `LIKE '%…%'` scan of
 * every candidate. Anything else (a name, part of a number, part of an address or code) keeps the
 * existing substring search unchanged.
 *
 * The exact lookup finds the same person a full address or number names, including stored
 * variants the normalizer treats as the same inbox or number ("a.b+jobs@gmail.com",
 * "+91 98765-43210"); names never contain a complete email, number or code, so nothing a user
 * expects from the substring search is lost.
 */
final class CandidateSearchTerm
{
    private const string CODE_PATTERN = '/^CAND-\d{4}-\d+$/i';

    private const string PHONE_PATTERN = '/^[\d\s+\-().]+$/';

    /**
     * Applies the exact lookup and returns true when the term is a complete identifier; returns
     * false (and leaves the query untouched) for any other term.
     *
     * @param  Builder<*>  $query  a candidates query
     * @param  bool  $email  false where the search never covered email (the candidate picker)
     */
    public static function applyExact(Builder $query, string $term, bool $email = true): bool
    {
        $term = trim($term);

        if (preg_match(self::CODE_PATTERN, $term) === 1) {
            $query->where($query->qualifyColumn('candidate_code'), strtoupper($term));

            return true;
        }

        if ($email && filter_var($term, FILTER_VALIDATE_EMAIL) !== false) {
            $query->where($query->qualifyColumn('email_normalized'), CandidateIdentityNormalizer::email($term));

            return true;
        }

        if (preg_match(self::PHONE_PATTERN, $term) === 1 && strlen((string) preg_replace('/\D+/', '', $term)) >= 10) {
            $mobile = CandidateIdentityNormalizer::mobile($term);

            $query->where(fn (Builder $q) => $q
                ->where($q->qualifyColumn('mobile_normalized'), $mobile)
                ->orWhere($q->qualifyColumn('alternate_mobile_normalized'), $mobile));

            return true;
        }

        return false;
    }
}
