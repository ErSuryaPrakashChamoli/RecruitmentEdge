<?php

namespace App\Services\AI\Orchestrator;

use App\Enums\AiMessageRole;
use App\Enums\AiToolCallStatus;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiToolCall;
use App\Models\User;
use App\Services\AI\Actions\ActionExecutor;
use App\Services\AI\Exceptions\AiRateLimitExceededException;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Tools\ToolRegistry;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The turn loop: builds context, calls the Gateway, and for each tool call the model requests
 * either executes it immediately (Read/Recommend) or stops the turn and leaves it Pending for a
 * human to approve (Write/External/HighImpact — spec section 26). Never called directly from a
 * Filament page/controller for anything beyond ask()/continueTurn() — those are the only two entry
 * points into the loop.
 */
class AiOrchestrator
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly ToolRegistry $registry,
        private readonly ConversationContextBuilder $contextBuilder,
        private readonly ActionExecutor $executor,
    ) {}

    /**
     * Starts a new turn from a user-typed message.
     *
     * @return array{message: AiMessage, pending: array<int, AiToolCall>}
     */
    public function ask(AiConversation $conversation, string $userMessage, User $user, ?callable $onDelta = null): array
    {
        $this->assertNotRateLimited($user);

        $conversation->messages()->create([
            'role' => AiMessageRole::User,
            'content' => $userMessage,
        ]);

        return $this->runTurn($conversation, $user, $onDelta);
    }

    /**
     * Resumes the loop after a pending tool call has been approved/rejected and no sibling tool
     * calls on the same assistant message remain pending. Adds no new user message.
     *
     * @return array{message: AiMessage, pending: array<int, AiToolCall>}
     */
    public function continueTurn(AiConversation $conversation, User $user, ?callable $onDelta = null): array
    {
        return $this->runTurn($conversation, $user, $onDelta);
    }

    /**
     * @return array{message: AiMessage, pending: array<int, AiToolCall>}
     */
    private function runTurn(AiConversation $conversation, User $user, ?callable $onDelta): array
    {
        $tools = $this->registry->definitionsForUser($user);
        $maxSteps = (int) config('ai.limits.max_tool_calls_per_turn');
        $steps = 0;
        $assistantMessage = null;
        $pending = [];

        while ($steps < $maxSteps) {
            $latestUserText = $this->latestUserMessageText($conversation);
            $messages = $this->contextBuilder->build($conversation, $latestUserText ?? '');

            $llmResponse = $onDelta !== null
                ? $this->gateway->stream($messages, $tools, 'balanced', $onDelta, $user, $conversation->id)
                : $this->gateway->generate($messages, $tools, 'balanced', $user, $conversation->id);

            $assistantMessage = $conversation->messages()->create([
                'role' => AiMessageRole::Assistant,
                'content' => $llmResponse->content,
                'input_tokens' => $llmResponse->usage['input_tokens'] ?? null,
                'output_tokens' => $llmResponse->usage['output_tokens'] ?? null,
                'cached_tokens' => $llmResponse->usage['cached_tokens'] ?? null,
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            if (! $llmResponse->hasToolCalls()) {
                break;
            }

            $pending = $this->processToolCalls($assistantMessage, $llmResponse->toolCalls, $user);

            if ($pending !== []) {
                break;
            }

            $steps++;
        }

        return ['message' => $assistantMessage, 'pending' => $pending];
    }

    /**
     * @param  array<int, array{id: string, name: string, arguments: array<string, mixed>}>  $toolCalls
     * @return array<int, AiToolCall> the tool calls now awaiting human approval, if any
     */
    private function processToolCalls(AiMessage $assistantMessage, array $toolCalls, User $user): array
    {
        $pending = [];

        foreach ($toolCalls as $call) {
            $tool = $this->registry->find($call['name']);
            $permitted = $tool !== null && $this->registry->userMayUse($user, $call['name']);

            if (! $permitted) {
                $toolCallRow = $assistantMessage->toolCalls()->create([
                    'tool_name' => $call['name'],
                    'provider_call_id' => $call['id'],
                    'arguments' => $call['arguments'],
                    'provider_metadata' => $call['metadata'] ?? null,
                    'risk_level' => 'read',
                    'status' => AiToolCallStatus::Failed,
                    'requires_confirmation' => false,
                ]);

                $toolCallRow->result()->create([
                    'output' => [],
                    'success' => false,
                    'error' => 'That tool is not available for your role.',
                ]);

                $assistantMessage->conversation->messages()->create([
                    'role' => AiMessageRole::Tool,
                    'content' => json_encode(['success' => false, 'error' => 'Tool not available or not permitted.']),
                    'tool_call_id' => $call['id'],
                    'tool_name' => $call['name'],
                ]);

                continue;
            }

            $requiresConfirmation = $tool->riskLevel()->requiresConfirmation();

            $toolCallRow = $assistantMessage->toolCalls()->create([
                'tool_name' => $tool->name(),
                'provider_call_id' => $call['id'],
                'arguments' => $call['arguments'],
                'provider_metadata' => $call['metadata'] ?? null,
                'risk_level' => $tool->riskLevel(),
                'status' => AiToolCallStatus::Pending,
                'requires_confirmation' => $requiresConfirmation,
            ]);

            if ($requiresConfirmation) {
                $pending[] = $toolCallRow;

                continue;
            }

            $this->executor->runImmediate($toolCallRow, $tool, $user);
        }

        return $pending;
    }

    private function latestUserMessageText(AiConversation $conversation): ?string
    {
        return $conversation->messages()
            ->where('role', AiMessageRole::User)
            ->reorder()
            ->orderByDesc('id')
            ->value('content');
    }

    private function assertNotRateLimited(User $user): void
    {
        $key = "ai-chat:{$user->id}";
        $max = (int) config('ai.limits.rate_limit_per_minute');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new AiRateLimitExceededException('You are sending messages too quickly. Please wait a moment and try again.');
        }

        RateLimiter::hit($key, 60);
    }
}
