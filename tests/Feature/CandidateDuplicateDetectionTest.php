<?php

use App\Enums\DuplicateMatchStatus;
use App\Enums\DuplicateMatchType;
use App\Models\Candidate;
use App\Models\CandidateDuplicateMatch;

test('creating a candidate with a duplicate mobile logs a match but does not block creation', function (): void {
    $existing = Candidate::factory()->create(['mobile' => '9876543210']);

    $new = Candidate::factory()->create(['mobile' => '9876543210']);

    expect(Candidate::count())->toBe(2);

    $match = CandidateDuplicateMatch::query()
        ->where('candidate_id', $new->id)
        ->where('matched_candidate_id', $existing->id)
        ->first();

    expect($match)->not->toBeNull()
        ->and($match->match_type)->toBe(DuplicateMatchType::Mobile)
        ->and($match->status)->toBe(DuplicateMatchStatus::PendingReview);
});

test('creating a candidate with a duplicate email logs a match', function (): void {
    $existing = Candidate::factory()->create(['email' => 'dup@example.com']);

    $new = Candidate::factory()->create(['email' => 'dup@example.com']);

    $match = CandidateDuplicateMatch::query()
        ->where('candidate_id', $new->id)
        ->where('matched_candidate_id', $existing->id)
        ->where('match_type', DuplicateMatchType::Email)
        ->first();

    expect($match)->not->toBeNull();
});

test('a candidate with no matching mobile or email logs nothing', function (): void {
    Candidate::factory()->create(['mobile' => '1111111111', 'email' => 'a@example.com']);
    $new = Candidate::factory()->create(['mobile' => '2222222222', 'email' => 'b@example.com']);

    expect(CandidateDuplicateMatch::query()->where('candidate_id', $new->id)->count())->toBe(0);
});
