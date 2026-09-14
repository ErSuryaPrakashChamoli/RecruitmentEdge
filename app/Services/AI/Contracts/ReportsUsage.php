<?php

namespace App\Services\AI\Contracts;

/**
 * Optional capability for providers whose embed()/search()/structured() calls return token usage
 * the capability interfaces can't carry (they return plain arrays). AiGateway reads it right after
 * each such call so ai_usage_logs gets real token counts — providers that can't report usage (or
 * test fakes) simply don't implement it and the log row keeps null tokens.
 */
interface ReportsUsage
{
    /**
     * Usage from this provider's most recent embed()/search()/structured() call, or an empty
     * array when that call returned none.
     *
     * @return array{input_tokens?: int, output_tokens?: int, cached_tokens?: int}
     */
    public function lastUsage(): array;
}
