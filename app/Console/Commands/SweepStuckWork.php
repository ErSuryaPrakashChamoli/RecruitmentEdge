<?php

namespace App\Console\Commands;

use App\Enums\AiToolCallStatus;
use App\Enums\CommunicationStatus;
use App\Events\CommunicationFailed;
use App\Jobs\SendCommunicationJob;
use App\Models\AiToolCall;
use App\Models\AuditLog;
use App\Models\CandidateCommunication;
use App\Services\Communication\ProviderCircuitBreaker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 8.7 (D8.7-013, DQ-87-04/09): finds work a lost job or a crashed process left half-done and
 * puts it in a state a person can see and act on. Never sends anything twice:
 *
 * - a message still Queued well after it was queued (its job was lost, or it was held while its
 *   provider was paused) is handed to SendCommunicationJob again — the job claims the row under a
 *   lock and is unique per message, so a message whose job is still waiting is not duplicated;
 * - a message stuck Sending (the worker died mid-send) is marked Failed: whether the provider got
 *   it is unknown, so it is left for an operator to check and resend;
 * - an AI action stuck Approved (the request died while running it) is marked Failed with an
 *   honest note that its effect is unknown.
 *
 * Each item is handled on its own: one bad row never stops the sweep.
 */
#[Signature('reliability:sweep')]
#[Description('Re-queue lost candidate messages and fail work left stuck by a crashed worker or request')]
class SweepStuckWork extends Command
{
    public const int CHUNK = 200;

    public function handle(ProviderCircuitBreaker $circuit): int
    {
        $requeued = $this->requeueHeldMessages($circuit);
        $sending = $this->failStuckSending();
        $aiActions = $this->failStuckAiActions();

        $this->info("Re-queued {$requeued} message(s); failed {$sending} message(s) stuck sending and {$aiActions} interrupted AI action(s).");

        return self::SUCCESS;
    }

    /**
     * Phase 8.9 (P89-PERF-007, ED-04): at most `requeue_max_per_run` messages per run, oldest first.
     * A large backlog is drained by the workers; re-dispatching all of it every five minutes only
     * repeated the unique-job checks. A lost message is reached on a later run.
     */
    private function requeueHeldMessages(ProviderCircuitBreaker $circuit): int
    {
        $limit = max(1, (int) config('communications.recovery.requeue_max_per_run', 1000));
        $count = 0;

        CandidateCommunication::query()
            ->where('status', CommunicationStatus::Queued)
            ->where('queued_at', '<', now()->subMinutes((int) config('communications.recovery.requeue_after_minutes', 10)))
            ->select(['id', 'provider'])
            ->chunkById(self::CHUNK, function ($messages) use ($circuit, $limit, &$count): bool {
                foreach ($messages as $message) {
                    if ($message->provider !== null && $circuit->isOpen($message->provider)) {
                        continue;
                    }

                    SendCommunicationJob::dispatch($message->id);

                    if (++$count >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        return $count;
    }

    private function failStuckSending(): int
    {
        $count = 0;

        CandidateCommunication::query()
            ->where('status', CommunicationStatus::Sending)
            ->where('updated_at', '<', now()->subMinutes((int) config('communications.recovery.stuck_sending_minutes', 30)))
            ->chunkById(self::CHUNK, function ($messages) use (&$count): void {
                foreach ($messages as $message) {
                    try {
                        $claimed = CandidateCommunication::query()->whereKey($message->id)->where('status', CommunicationStatus::Sending)
                            ->update(['status' => CommunicationStatus::Failed->value, 'failed_at' => now(), 'error' => 'Delivery state unknown: the send was interrupted. Check with the provider before resending.']);

                        if ($claimed === 1) {
                            $message->refresh();
                            AuditLog::record($message, 'communication_failed', null, ['provider' => $message->provider, 'error' => 'interrupted_send', 'swept' => true]);
                            CommunicationFailed::dispatch($message);
                            $count++;
                        }
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        return $count;
    }

    private function failStuckAiActions(): int
    {
        $count = 0;

        AiToolCall::query()
            ->where('status', AiToolCallStatus::Approved)
            ->where('approved_at', '<', now()->subMinutes((int) config('ai.limits.stuck_approved_minutes', 30)))
            ->chunkById(self::CHUNK, function ($calls) use (&$count): void {
                foreach ($calls as $call) {
                    try {
                        $claimed = AiToolCall::query()->whereKey($call->id)->where('status', AiToolCallStatus::Approved->value)
                            ->update(['status' => AiToolCallStatus::Failed->value, 'executed_at' => now()]);

                        if ($claimed === 1) {
                            $call->result()->create([
                                'output' => ['success' => false, 'error' => 'interrupted'],
                                'success' => false,
                                'error' => 'The action was interrupted while running. It may have partly completed — check the record before trying again.',
                            ]);
                            AuditLog::record($call, 'ai_action_interrupted', null, ['tool' => $call->tool_name]);
                            $count++;
                        }
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        return $count;
    }
}
