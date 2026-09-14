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

test('changing a candidate mobile to an existing number logs a match on update', function (): void {
    $existing = Candidate::factory()->create(['mobile' => '9000000001']);
    $candidate = Candidate::factory()->create(['mobile' => '9000000002']);

    expect(CandidateDuplicateMatch::query()->where('candidate_id', $candidate->id)->count())->toBe(0);

    $candidate->update(['mobile' => '9000000001']);

    expect(CandidateDuplicateMatch::query()
        ->where('candidate_id', $candidate->id)
        ->where('matched_candidate_id', $existing->id)
        ->where('match_type', DuplicateMatchType::Mobile)
        ->count())->toBe(1);
});

test('re-running detection on update never duplicates an existing match row', function (): void {
    Candidate::factory()->create(['email' => 'same@example.com']);
    $candidate = Candidate::factory()->create(['email' => 'same@example.com', 'mobile' => '9000000003']);

    $candidate->update(['mobile' => '9000000004']);
    $candidate->update(['email' => 'SAME@example.com']);
    $candidate->update(['email' => 'same@example.com']);

    expect(CandidateDuplicateMatch::query()->where('candidate_id', $candidate->id)->count())->toBe(1);
});

test('updating unrelated fields does not re-run duplicate detection', function (): void {
    $candidate = Candidate::factory()->create(['mobile' => '9000000005']);
    Candidate::withoutEvents(fn () => Candidate::factory()->create(['mobile' => '9000000005']));

    $candidate->update(['remarks' => 'Called twice']);

    expect(CandidateDuplicateMatch::query()->where('candidate_id', $candidate->id)->count())->toBe(0);
});
