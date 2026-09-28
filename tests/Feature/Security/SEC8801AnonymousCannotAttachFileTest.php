<?php

use App\Models\CandidateDocument;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Sec88ContainmentHelpers.php';

/**
 * SEC-88-01: an anonymous submission never attaches a file to an existing candidate, and the
 * uploaded file of a held submission is not stored at all.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->posting = sec88Posting();
    $this->victim = sec88ExistingCandidate();
});

test('no file is attached to or stored for an existing candidate', function (array $variant): void {
    CandidateDocument::factory()->create(['candidate_id' => $this->victim->id]);
    $before = $this->victim->documents()->pluck('file_path', 'id')->all();

    sec88Apply($this->posting, $variant);

    expect($this->victim->documents()->pluck('file_path', 'id')->all())->toBe($before)
        ->and(CandidateDocument::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles("candidate-documents/{$this->victim->id}"))->toBe([])
        ->and(Storage::disk('local')->allFiles('candidate-documents'))->toBe([]);
})->with(sec88MatchingVariants());

test('a genuinely new applicant\'s file is still stored on their own new record', function (): void {
    sec88Apply($this->posting);

    $document = CandidateDocument::query()->sole();

    expect($document->candidate_id)->not->toBe($this->victim->id)
        ->and(Storage::disk('local')->exists($document->file_path))->toBeTrue();
});
