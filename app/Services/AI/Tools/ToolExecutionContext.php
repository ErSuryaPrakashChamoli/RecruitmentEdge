<?php

namespace App\Services\AI\Tools;

/**
 * Request-scoped holder for the conversation a tool is currently running inside. ActionExecutor
 * sets it around AiTool::handle(), and AiGateway falls back to it when a call site doesn't pass a
 * conversation id explicitly — so model/embedding/web-search calls made from inside a tool are
 * attributed to the right conversation in ai_usage_logs without widening the AiTool interface.
 */
class ToolExecutionContext
{
    private ?int $conversationId = null;

    public function conversationId(): ?int
    {
        return $this->conversationId;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runInConversation(?int $conversationId, callable $callback): mixed
    {
        $previous = $this->conversationId;
        $this->conversationId = $conversationId;

        try {
            return $callback();
        } finally {
            $this->conversationId = $previous;
        }
    }
}
