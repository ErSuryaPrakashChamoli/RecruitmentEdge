<?php

namespace App\Services\AI\Tools\Concerns;

use App\Models\User;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Gateway\AiGateway;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared guard for tools that call a model themselves (generation/summarisation/planning). A tool
 * must never let a missing key or a provider outage throw out of handle() — it returns null here
 * and the tool turns that into a graceful ToolResult (modelUnavailable()) or skips the optional
 * narrative, so the rest of the turn keeps working.
 */
trait CallsLanguageModel
{
    /**
     * @param  array<int, LlmMessage>  $messages
     */
    protected function generateText(AiGateway $gateway, array $messages, string $category, User $user): ?string
    {
        if (! $gateway->isConfigured()) {
            return null;
        }

        try {
            $response = $gateway->generate($messages, [], $category, $user);
        } catch (Throwable $e) {
            Log::warning('AI tool model call failed', ['tool' => static::class, 'exception' => $e->getMessage()]);

            return null;
        }

        return $response->configured && filled($response->content) ? $response->content : null;
    }

    protected function modelUnavailable(AiGateway $gateway, string $task): ToolResult
    {
        return ToolResult::fail($gateway->isConfigured()
            ? "The AI service could not {$task} right now. Please try again in a moment."
            : "AI is not configured, so I cannot {$task} right now. Ask an administrator to set AI_PROVIDER and its API key (GEMINI_API_KEY or OPENAI_API_KEY).");
    }
}
