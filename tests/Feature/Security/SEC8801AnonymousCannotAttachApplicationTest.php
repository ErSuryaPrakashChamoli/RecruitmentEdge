<?php

use App\Enums\ApplicationStatus;
use App\Models\CandidateApplication;
use App\Models\RecruitmentRejectionReason;
use App\Services\StageTransitionService;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Sec88ContainmentHelpers.php';

/**
 * SEC-88-01: an anonymous submission never attaches an application to an existing candidate,
 * whatever state that candidate's applications are in.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->posting = sec88Posting();
    $this->victim = sec88ExistingCandidate();
});

test('no application is attached to an existing candidate', function (?string $existingState): void {
    if ($existingState !== null) {
        $application = CandidateApplication::factory()->create(['candidate_id' => $this->victim->id]);

        match ($existingState) {
            'active' => null,
            'closed' => app(StageTransitionService::class)->dropout($application, RecruitmentRejectionReason::factory()->create()),
            'rejected' => app(StageTransitionService::class)->reject($application, RecruitmentRejectionReason::factory()->create()),
        };
    }

    $before = $this->victim->applications()->get(['id', 'status', 'requisition_id'])->toArray();

    foreach (sec88MatchingVariants() as [$variant]) {
        sec88Apply($this->posting, $variant);
    }

    expect($this->victim->applications()->get(['id', 'status', 'requisition_id'])->toArray())->toBe($before)
        ->and(CandidateApplication::query()->where('requisition_id', $this->posting->requisition_id)->count())->toBe(0);
})->with([
    'no application' => [null],
    'active application' => ['active'],
    'closed application' => ['closed'],
    'rejected application' => ['rejected'],
]);

test('an existing application for the same requisition is not reported back or touched', function (): void {
    $existing = CandidateApplication::factory()->create(['candidate_id' => $this->victim->id, 'requisition_id' => $this->posting->requisition_id, 'status' => ApplicationStatus::Active]);

    sec88Apply($this->posting, ['email' => 'real.person@example.com'])->assertSessionMissing('careers_reference')->assertSessionMissing('careers_existing');

    expect($existing->fresh()->updated_at->equalTo($existing->updated_at))->toBeTrue()
        ->and(CandidateApplication::query()->count())->toBe(1);
});
