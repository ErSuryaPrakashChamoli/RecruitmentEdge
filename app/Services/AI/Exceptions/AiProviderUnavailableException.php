<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * Thrown by embed()/search() when no credential is configured. complete()/stream() never throw
 * this — they degrade to a canned "AI is not configured" chat reply instead, since that path is
 * user-facing conversation rather than a background job or tool call. Callers of embed()/search()
 * (DocumentIngestionService, WebResearchTool) catch this and fail gracefully.
 */
class AiProviderUnavailableException extends RuntimeException {}
