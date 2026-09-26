<?php

namespace App\Services;

use App\Enums\DuplicateMatchType;
use App\Events\DuplicateOverrideApproved;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Employee;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Deterministic duplicate-candidate detection (Section 10, extended in Phase 4). Never blocks or
 * merges on its own: it returns structured matches, and callers decide — the Candidate create form
 * and ReferralService require an audited justification to create a new candidate over a strong
 * match; CandidateObserver logs matches for HR review.
 *
 * Signals, strongest first: exact mobile, exact email, normalised mobile, normalised email,
 * alternate-mobile cross match, and same normalised name plus a partial contact match. Lookups
 * use the indexed *_normalized columns maintained by Candidate's saving hook.
 */
class CandidateDuplicateDetector
{
    public const int STRONG_CONFIDENCE = 85;

    /**
     * Matches for a would-be candidate described by raw form attributes (full_name, mobile,
     * alternate_mobile, email), one per existing candidate carrying its strongest signal,
     * highest confidence first.
     *
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, DuplicateCandidateMatch>
     */
    public function detect(array $attributes, ?int $ignoreCandidateId = null): Collection
    {
        $mobile = filled($attributes['mobile'] ?? null) ? trim((string) $attributes['mobile']) : null;
        $email = filled($attributes['email'] ?? null) ? trim((string) $attributes['email']) : null;
        $mobileKey = CandidateIdentityNormalizer::mobile($mobile);
        $alternateKey = CandidateIdentityNormalizer::mobile($attributes['alternate_mobile'] ?? null);
        $emailKey = CandidateIdentityNormalizer::email($email);
        $nameKey = CandidateIdentityNormalizer::name($attributes['full_name'] ?? null);

        $phoneKeys = array_values(array_filter([$mobileKey, $alternateKey]));

        if ($phoneKeys === [] && $emailKey === null && $nameKey === null) {
            return collect();
        }

        $candidates = Candidate::query()
            ->when($ignoreCandidateId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreCandidateId))
            ->where(function (Builder $q) use ($phoneKeys, $emailKey, $nameKey): void {
                if ($phoneKeys !== []) {
                    $q->orWhereIn('mobile_normalized', $phoneKeys)->orWhereIn('alternate_mobile_normalized', $phoneKeys);
                }

                if ($emailKey !== null) {
                    $q->orWhere('email_normalized', $emailKey);
                }

                if ($nameKey !== null) {
                    $q->orWhere('name_normalized', $nameKey);
                }
            })
            ->limit(50)
            ->get();

        return $candidates
            ->map(fn (Candidate $candidate) => $this->evaluate($candidate, $mobile, $email, $mobileKey, $alternateKey, $emailKey, $nameKey))
            ->filter()
            ->sortByDesc(fn (DuplicateCandidateMatch $match) => $match->confidence)
            ->values();
    }

    /**
     * Only the matches strong enough to require a justification before creating a new candidate.
     *
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, DuplicateCandidateMatch>
     */
    public function strongMatches(array $attributes, ?int $ignoreCandidateId = null): Collection
    {
        return $this->detect($attributes, $ignoreCandidateId)->filter(fn (DuplicateCandidateMatch $match) => $match->isStrong())->values();
    }

    /**
     * Existing candidates that look like duplicates of a saved candidate — CandidateObserver logs
     * these for HR review (kept for backward compatibility with its original shape).
     *
     * @return Collection<int, array{candidate: Candidate, type: DuplicateMatchType}>
     */
    public function findMatches(Candidate $candidate): Collection
    {
        return $this->detect($candidate->only(['full_name', 'mobile', 'alternate_mobile', 'email']), $candidate->id)
            ->map(fn (DuplicateCandidateMatch $match) => ['candidate' => $match->candidate, 'type' => $match->type])
            ->values();
    }

    /**
     * Records a justified decision to create a new candidate despite strong matches — the
     * authoritative AuditLog row plus the DuplicateOverrideApproved hook.
     *
     * @param  Collection<int, DuplicateCandidateMatch>  $matches
     */
    public function recordOverride(Candidate $candidate, Collection $matches, string $justification, ?Employee $actor = null): void
    {
        if (blank($justification)) {
            throw new DomainException('A justification is required to create a candidate that matches an existing one.');
        }

        $rows = $matches->map(fn (DuplicateCandidateMatch $match) => $match->toArray())->values()->all();

        AuditLog::record($candidate, 'duplicate_override', null, ['justification' => $justification, 'matches' => $rows]);

        DuplicateOverrideApproved::dispatch($candidate, $rows, $justification, $actor);
    }

    private function evaluate(
        Candidate $candidate,
        ?string $mobile,
        ?string $email,
        ?string $mobileKey,
        ?string $alternateKey,
        ?string $emailKey,
        ?string $nameKey,
    ): ?DuplicateCandidateMatch {
        $signals = [];

        if ($mobile !== null && $candidate->mobile === $mobile) {
            $signals[DuplicateMatchType::Mobile->value] = 'mobile';
        } elseif ($mobileKey !== null && $candidate->mobile_normalized === $mobileKey) {
            $signals[DuplicateMatchType::NormalizedMobile->value] = 'mobile';
        }

        if ($email !== null && $candidate->email === $email) {
            $signals[DuplicateMatchType::Email->value] = 'email';
        } elseif ($emailKey !== null && $candidate->email_normalized === $emailKey) {
            $signals[DuplicateMatchType::NormalizedEmail->value] = 'email';
        }

        $crossPhone = ($mobileKey !== null && $candidate->alternate_mobile_normalized === $mobileKey)
            || ($alternateKey !== null && in_array($alternateKey, array_filter([$candidate->mobile_normalized, $candidate->alternate_mobile_normalized]), true));

        if ($crossPhone) {
            $signals[DuplicateMatchType::AlternateMobile->value] = 'alternate_mobile';
        }

        if ($signals === [] && $nameKey !== null && $candidate->name_normalized === $nameKey && $this->partialContactMatch($candidate, $mobileKey, $emailKey)) {
            $signals[DuplicateMatchType::NameAndContact->value] = 'full_name';
        }

        if ($signals === []) {
            return null;
        }

        $types = array_map(fn (string $value) => DuplicateMatchType::from($value), array_keys($signals));
        usort($types, fn (DuplicateMatchType $a, DuplicateMatchType $b) => $b->confidence() <=> $a->confidence());
        $strongest = $types[0];

        return new DuplicateCandidateMatch(
            candidate: $candidate,
            type: $strongest,
            confidence: $strongest->confidence(),
            matchingFields: array_values(array_unique($signals)),
            reason: 'Matched on '.implode(', ', array_map(fn (DuplicateMatchType $type) => mb_strtolower($type->label()), $types)).'.',
        );
    }

    /**
     * Same last 7 mobile digits, or same email mailbox name — only used together with an exact
     * normalised name, never alone.
     */
    private function partialContactMatch(Candidate $candidate, ?string $mobileKey, ?string $emailKey): bool
    {
        $mobileTail = $mobileKey !== null && strlen($mobileKey) >= 7 ? substr($mobileKey, -7) : null;
        $emailLocal = $emailKey !== null ? strstr($emailKey, '@', true) : false;

        return ($mobileTail !== null && $candidate->mobile_normalized !== null && str_ends_with($candidate->mobile_normalized, $mobileTail))
            || ($emailLocal !== false && $emailLocal !== '' && $candidate->email_normalized !== null && strstr($candidate->email_normalized, '@', true) === $emailLocal);
    }
}
