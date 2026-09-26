<?php

use App\Enums\OutcomeType;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Services\Outcomes\OutcomeEvaluator;
use Illuminate\Support\Facades\Log;

test('one malformed record does not abort the evaluation; it is logged, left unrecorded and retried', function (): void {
    Log::spy();
    $good = HiringOutcomeSnapshot::factory()->count(3)->create(['facts' => ['stage_days' => ['screened' => 2.0]]]);
    $bad = HiringOutcomeSnapshot::factory()->create(['facts' => ['stage_days' => 'not-a-list']]);

    $evaluator = app(OutcomeEvaluator::class);
    $counts = $evaluator->evaluate();

    expect($counts['process_outcomes'])->toBe(3)
        ->and($counts['failed'])->toBe(1)
        ->and($evaluator->failures()[0])->toMatchArray(['step' => 'process_outcomes', 'record' => "HiringOutcomeSnapshot #{$bad->id}"])
        ->and(HiringOutcome::query()->where('hiring_outcome_snapshot_id', $bad->id)->whereIn('outcome_type', [OutcomeType::TimeToHire->value, OutcomeType::TimeInStage->value])->exists())->toBeFalse()
        ->and(HiringOutcome::query()->whereIn('hiring_outcome_snapshot_id', $good->pluck('id'))->where('outcome_type', OutcomeType::TimeToHire->value)->count())->toBe(3);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['record'] === "HiringOutcomeSnapshot #{$bad->id}")->once();

    $again = $evaluator->evaluate();

    expect($again['process_outcomes'])->toBe(0)
        ->and($again['failed'])->toBe(1)
        ->and(HiringOutcome::query()->where('outcome_type', OutcomeType::TimeToHire->value)->count())->toBe(3);
});

test('the scheduled command reports failed records and exits non-zero', function (): void {
    HiringOutcomeSnapshot::factory()->create(['facts' => ['stage_days' => 'not-a-list']]);

    $this->artisan('outcomes:evaluate')->expectsOutputToContain('will be retried on the next pass')->assertFailed();
});
