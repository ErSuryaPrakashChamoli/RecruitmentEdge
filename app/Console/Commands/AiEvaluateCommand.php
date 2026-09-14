<?php

namespace App\Console\Commands;

use App\Enums\AiToolCallStatus;
use App\Models\AiConversation;
use App\Models\AiEvaluation;
use App\Models\AiToolCall;
use App\Models\User;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Orchestrator\AiOrchestrator;
use App\Services\AI\Tools\ToolRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Evaluation pass over every stored AiEvaluation (spec section 51).
 *
 * Static mode (default, credential-free, never calls a provider): the expected_tool is registered,
 * declares the expected_permission, and — when `assertions.requires_confirmation` is set — its risk
 * level agrees with that assertion.
 *
 * Live mode (--live, needs a configured provider): additionally sends each question through
 * AiOrchestrator as --user (default: the first user with ai.manage) in a fresh archived
 * conversation and checks the model actually called the expected tool. Write/External/HighImpact
 * calls stop at Pending as always and are never approved, so no action is ever executed.
 */
#[Signature('ai:evaluate
    {--live : Also run each question through the configured AI provider and check the expected tool was called}
    {--user= : User id or email to run live evaluations as (default: first user with ai.manage)}')]
#[Description('Run the stored AI evaluation suite against the current tool registry')]
class AiEvaluateCommand extends Command
{
    public function handle(ToolRegistry $registry, AiGateway $gateway, AiOrchestrator $orchestrator): int
    {
        $evaluations = AiEvaluation::all();

        if ($evaluations->isEmpty()) {
            $this->warn('No AI evaluations found. Seed some with AiEvaluationSeeder first.');

            return self::SUCCESS;
        }

        $live = (bool) $this->option('live');
        $liveUser = null;

        if ($live) {
            if (! $gateway->isConfigured()) {
                $this->error('--live needs a configured AI provider (set AI_PROVIDER and its API key — GEMINI_API_KEY or OPENAI_API_KEY). Run without --live for the static checks.');

                return self::FAILURE;
            }

            $liveUser = $this->resolveLiveUser();

            if ($liveUser === null) {
                $this->error('No user found to run live evaluations as. Pass --user=<id|email>.');

                return self::FAILURE;
            }
        }

        $failures = 0;

        foreach ($evaluations as $evaluation) {
            [$passed, $notes] = $this->checkStatic($evaluation, $registry);
            $calledTools = [];

            if ($live) {
                [$livePassed, $liveNotes, $calledTools] = $this->checkLive($evaluation, $orchestrator, $liveUser);
                $passed = $passed && $livePassed;
                $notes = [...$notes, ...$liveNotes];
            }

            $evaluation->runs()->create([
                'passed' => $passed,
                'actual_output' => ['mode' => $live ? 'live' : 'static', 'notes' => $notes, 'called_tools' => $calledTools],
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
        $this->info(($evaluations->count() - $failures).' / '.$evaluations->count().' evaluations passed'.($live ? ' (live).' : '.'));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: bool, 1: array<int, string>}
     */
    private function checkStatic(AiEvaluation $evaluation, ToolRegistry $registry): array
    {
        $notes = [];
        $passed = true;

        if (blank($evaluation->expected_tool)) {
            return [$passed, $notes];
        }

        $tool = $registry->find($evaluation->expected_tool);

        if ($tool === null) {
            return [false, ["Expected tool [{$evaluation->expected_tool}] is not registered."]];
        }

        if (filled($evaluation->expected_permission) && $tool->permission() !== $evaluation->expected_permission) {
            $passed = false;
            $notes[] = "Tool [{$evaluation->expected_tool}] declares permission [".($tool->permission() ?? 'none')."], expected [{$evaluation->expected_permission}].";
        }

        $assertions = $evaluation->assertions ?? [];

        if (array_key_exists('requires_confirmation', $assertions)) {
            $expected = (bool) $assertions['requires_confirmation'];
            $actual = $tool->riskLevel()->requiresConfirmation();

            if ($expected !== $actual) {
                $passed = false;
                $notes[] = "Tool [{$evaluation->expected_tool}] is {$tool->riskLevel()->label()} risk (requires confirmation: ".($actual ? 'yes' : 'no').'), but the evaluation expects requires_confirmation ['.($expected ? 'true' : 'false').'].';
            }
        }

        return [$passed, $notes];
    }

    /**
     * @return array{0: bool, 1: array<int, string>, 2: array<int, string>}
     */
    private function checkLive(AiEvaluation $evaluation, AiOrchestrator $orchestrator, User $user): array
    {
        $conversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'title' => "ai:evaluate — {$evaluation->name}",
            'status' => 'archived',
            'last_message_at' => now(),
        ]);

        RateLimiter::clear(AiOrchestrator::rateLimitKey($user));

        try {
            $orchestrator->ask($conversation, $evaluation->question, $user);
        } catch (Throwable $e) {
            return [false, ["Live run failed: {$e->getMessage()}"], []];
        }

        /** @var Collection<int, AiToolCall> $toolCalls */
        $toolCalls = AiToolCall::query()
            ->whereHas('message', fn (Builder $message) => $message->where('conversation_id', $conversation->id))
            ->get(['tool_name', 'status']);

        $calledTools = $toolCalls->pluck('tool_name')->unique()->values()->all();

        if (blank($evaluation->expected_tool)) {
            return [true, ['Live: called '.($calledTools === [] ? 'no tools' : implode(', ', $calledTools)).'.'], $calledTools];
        }

        $expectedCall = $toolCalls->firstWhere('tool_name', $evaluation->expected_tool);

        if ($expectedCall === null) {
            return [false, ["Live: expected tool [{$evaluation->expected_tool}] was not called (called: ".($calledTools === [] ? 'none' : implode(', ', $calledTools)).').'], $calledTools];
        }

        if (($evaluation->assertions['requires_confirmation'] ?? false) && $expectedCall->status !== AiToolCallStatus::Pending) {
            return [false, ["Live: [{$evaluation->expected_tool}] should have stopped for confirmation but is {$expectedCall->status->label()}."], $calledTools];
        }

        return [true, ["Live: [{$evaluation->expected_tool}] was called."], $calledTools];
    }

    private function resolveLiveUser(): ?User
    {
        $option = $this->option('user');

        if (filled($option)) {
            return User::query()
                ->where(fn (Builder $query) => is_numeric($option)
                    ? $query->whereKey((int) $option)
                    : $query->where('email', $option))
                ->first();
        }

        return User::permission('ai.manage')->orderBy('id')->first();
    }
}
