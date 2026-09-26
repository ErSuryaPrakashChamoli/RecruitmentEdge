<?php

use App\Enums\CandidateStage;
use App\Models\CandidateApplication;

test('the backfill command gives legacy requisitions the default pipeline and reports what it did', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);

    $this->artisan('recruitment:assign-default-pipelines')
        ->expectsOutputToContain('Assigned the default pipeline to 1 requisition(s)')
        ->assertSuccessful();

    expect($application->fresh()->pipelineStage->code)->toBe('selected');
});
