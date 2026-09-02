<?php

namespace App\Services\AI\Actions;

use App\Enums\AiMessageRole;
use App\Enums\AiToolCallStatus;
use App\Models\AiActionLog;
use App\Models\AiToolCall;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Exceptions\AiRateLimitExceededException;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\AI\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The only class allowed to actually run an AiTool::handle(). AiOrchestrator calls run() directly
 * for Read/Recommend tools (no confirmation needed); Write/External/HighImpact tools only ever
 * reach run() via approve(), which is gated on ai.actions.execute and a Pending status check —
 * nothing the model outputs can skip this (spec section 26).
 */
class ActionExecutor
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ConfirmationGate $gate,
    ) {}

    /**
     * Executes a tool call that does not require confirmation. Called by AiOrchestrator only for
     * Read/Recommend risk tools.
     */
    public function runImmediate(AiToolCall $toolCall, AiTool $tool, User $user): ToolResult
    {
        return $this->execute($toolCall, $tool, $user);
    }

    /**
     * Executes a previously-pending Write/External/HighImpact tool call after a human with
     * ai.actions.execute has approved it.
     */
    public function approve(AiToolCall $toolCall, User $actor): ToolResult
    {
        if (! $this->gate->canApprove($actor)) {
            throw new \DomainException('You do not have permission to approve AI actions.');
        }

        if ($toolCall->status !== AiToolCallStatus::Pending) {
            throw new \DomainException('This action has already been decided.');
        }

        $tool = $this->registry->find($toolCall->tool_name);

        if ($tool === null) {
            throw new \DomainException('This tool is no longer available.');
        }

        if (RateLimiter::tooManyAttempts("ai-action:{$actor->id}", (int) config('ai.limits.action_rate_limit_per_minute'))) {
            throw new AiRateLimitExceededException('You are approving AI actions too quickly. Please wait a moment.');
        }

        RateLimiter::hit("ai-action:{$actor->id}", 60);

        $toolCall->forceFill(['approved_by' => $actor->id, 'approved_at' => now()])->save();

        return $this->execute($toolCall, $tool, $actor);
    }

    /**
     * Declines a pending tool call. The model is told (via a synthetic tool-output message) that
     * the user declined, so the conversation can continue coherently.
     */
    public function reject(AiToolCall $toolCall, User $actor, ?string $reason = null): void
    {
        if (! $this->gate->canApprove($actor)) {
            throw new \DomainException('You do not have permission to decide on AI actions.');
        }

        if ($toolCall->status !== AiToolCallStatus::Pending) {
            throw new \DomainException('This action has already been decided.');
        }

        $toolCall->forceFill([
            'status' => AiToolCallStatus::Rejected,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ])->save();

        $summary = $reason ?? 'The user declined to approve this action.';

        $toolCall->result()->create([
            'output' => ['declined' => true],
            'success' => false,
            'error' => $summary,
        ]);

        $this->appendToolOutputMessage($toolCall, ['success' => false, 'error' => $summary]);

        AiActionLog::query()->create([
            'user_id' => $actor->id,
            'conversation_id' => $toolCall->message->conversation_id,
            'tool_name' => $toolCall->tool_name,
            'risk_level' => $toolCall->risk_level,
            'input' => $toolCall->arguments,
            'result_summary' => $summary,
            'status' => 'rejected',
        ]);
    }

    /**
     * Whether every tool call attached to the assistant message that produced $toolCall has been
     * resolved (executed/rejected/failed) — used to decide whether the conversation can continue.
     */
    public function hasUnresolvedSiblings(AiToolCall $toolCall): bool
    {
        return $toolCall->message->toolCalls()->where('status', AiToolCallStatus::Pending)->exists();
    }

    private function execute(AiToolCall $toolCall, AiTool $tool, User $user): ToolResult
    {
        try {
            $result = $tool->handle($toolCall->arguments ?? [], $user);
        } catch (Throwable $e) {
            Log::error('AI tool execution failed', ['tool' => $tool->name(), 'exception' => $e->getMessage()]);
            $result = ToolResult::fail('Something went wrong while running this tool. The recruitment data itself was not affected.');
        }

        $toolCall->result()->create([
            'output' => $result->toArray(),
            'success' => $result->success,
            'error' => $result->error,
        ]);

        $toolCall->forceFill([
            'status' => $result->success ? AiToolCallStatus::Executed : AiToolCallStatus::Failed,
            'executed_at' => now(),
        ])->save();

        $this->appendToolOutputMessage($toolCall, $result->toArray());

        AiActionLog::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $toolCall->message->conversation_id,
            'tool_name' => $tool->name(),
            'risk_level' => $tool->riskLevel(),
            'entity_type' => $result->data['entity_type'] ?? null,
            'entity_ids' => $result->data['entity_ids'] ?? null,
            'input' => $toolCall->arguments,
            'result_summary' => $result->summary ?? ($result->success ? 'Completed' : $result->error),
            'status' => $result->success ? 'executed' : 'failed',
        ]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function appendToolOutputMessage(AiToolCall $toolCall, array $output): void
    {
        $toolCall->message->conversation->messages()->create([
            'role' => AiMessageRole::Tool,
            'content' => json_encode($output),
            'tool_call_id' => $toolCall->provider_call_id,
            'tool_name' => $toolCall->tool_name,
        ]);
    }
}
