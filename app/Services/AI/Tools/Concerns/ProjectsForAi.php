<?php

namespace App\Services\AI\Tools\Concerns;

use App\Services\AI\Privacy\AiProjector;

/**
 * Phase 8.1: tools build every provider-bound record representation through AiProjector.
 *
 * Resolved at call time, not injected: tools live in the singleton ToolRegistry, while the
 * projector's AiSensitiveValues register is scoped to the current request/job — a constructor
 * dependency would pin the first request's register for the life of a worker.
 */
trait ProjectsForAi
{
    protected function projector(): AiProjector
    {
        return app(AiProjector::class);
    }
}
