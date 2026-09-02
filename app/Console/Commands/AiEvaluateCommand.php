<?php

namespace App\Console\Commands;

use App\Models\AiEvaluation;
use App\Services\AI\Tools\ToolRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * A lightweight, credential-free evaluation pass (spec section 51): for every stored AiEvaluation,
 * checks that its expected_tool is actually registered and declares the expected_permission. This
 * catches tool-registry regressions (renamed/removed tool, permission drift) on every run, without
 * requiring a live LLM key — full end-to-end "does the model pick the right tool for this
 * question" evaluation is the natural next step once a provider key is configured.
 */
#[Signature('ai:evaluate')]
#[Description('Run the stored AI evaluation suite against the current tool registry')]
class AiEvaluateCommand extends Command
{
    public function handle(ToolRegistry $registry): int
    {
        $evaluations = AiEvaluation::all();

        if ($evaluations->isEmpty()) {
            $this->warn('No AI evaluations found. Seed some with AiEvaluationSeeder first.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($evaluations as $evaluation) {
            [$passed, $notes] = $this->check($evaluation, $registry);

            $evaluation->runs()->create([
                'passed' => $passed,
                'actual_output' => ['notes' => $notes],
                'notes' => implode(' ', $notes),
                'run_at' => now(),
            ]);

            $failures += $passed ? 0 : 1;
            $this->line(($passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>')." {$evaluation->name}");

            foreach ($notes as $note) {
                $this->line("  - {$note}");
            }
        }

        $this->newLine();
        $this->info(($evaluations->count() - $failures).' / '.$evaluations->count().' evaluations passed.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: bool, 1: array<int, string>}
     */
    private function check(AiEvaluation $evaluation, ToolRegistry $registry): array
    {
        $notes = [];
        $passed = true;

        if (filled($evaluation->expected_tool)) {
            $tool = $registry->find($evaluation->expected_tool);

            if ($tool === null) {
                $passed = false;
                $notes[] = "Expected tool [{$evaluation->expected_tool}] is not registered.";
            } elseif (filled($evaluation->expected_permission) && $tool->permission() !== $evaluation->expected_permission) {
                $passed = false;
                $notes[] = "Tool [{$evaluation->expected_tool}] declares permission [".($tool->permission() ?? 'none')."], expected [{$evaluation->expected_permission}].";
            }
        }

        return [$passed, $notes];
    }
}
