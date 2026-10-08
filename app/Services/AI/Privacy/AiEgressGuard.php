<?php

namespace App\Services\AI\Privacy;

use App\Services\AI\DTO\LlmMessage;
use Illuminate\Support\Facades\Log;

/**
 * Layer 3 of the Phase 8.1 privacy boundary: every provider-bound payload passes through here
 * from AiGateway — chat, streaming, structured output, embeddings and web research — whatever
 * the caller (Copilot, tools, intelligence jobs, RAG ingestion, dashboard insights).
 *
 * `redact` (production default) removes what it finds and logs the counts; `block` (tests and
 * strict environments) throws so a regression fails loudly. There is deliberately no "off" mode:
 * any other configured value is treated as `redact`, so configuration cannot disable the guard.
 * Logs carry kinds and counts only, never the values.
 */
class AiEgressGuard
{
    public const string MODE_REDACT = 'redact';

    public const string MODE_BLOCK = 'block';

    public function __construct(private readonly AiPayloadSanitizer $sanitizer) {}

    public static function mode(): string
    {
        return config('ai.privacy.egress_mode') === self::MODE_BLOCK ? self::MODE_BLOCK : self::MODE_REDACT;
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @return array<int, LlmMessage>
     */
    public function messages(array $messages, string $operation): array
    {
        $counts = [];
        $clean = [];

        foreach ($messages as $message) {
            $content = $message->content;

            if ($content !== null) {
                $result = $message->role === 'tool'
                    ? $this->sanitizer->sanitizeJson($content)
                    : $this->sanitizer->sanitizeText($content);
                $content = $result['text'];
                $counts = AiPayloadSanitizer::merge($counts, $result['counts']);
            }

            $toolCalls = $message->toolCalls;

            if ($toolCalls !== null) {
                foreach ($toolCalls as $index => $call) {
                    ['payload' => $arguments, 'counts' => $found] = $this->sanitizer->sanitizeStrings($call['arguments'] ?? []);
                    $toolCalls[$index]['arguments'] = $arguments;
                    $counts = AiPayloadSanitizer::merge($counts, $found);
                }
            }

            $clean[] = new LlmMessage($message->role, $content, $toolCalls, $message->toolCallId, $message->toolName);
        }

        $this->report($counts, $operation);

        return $clean;
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, string>
     */
    public function texts(array $texts, string $operation): array
    {
        $counts = [];

        $clean = array_map(function (string $text) use (&$counts): string {
            $result = $this->sanitizer->sanitizeText($text);
            $counts = AiPayloadSanitizer::merge($counts, $result['counts']);

            return $result['text'];
        }, $texts);

        $this->report($counts, $operation);

        return $clean;
    }

    public function query(string $query, string $operation): string
    {
        return $this->texts([$query], $operation)[0];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function report(array $counts, string $operation): void
    {
        if ($counts === []) {
            return;
        }

        if (self::mode() === self::MODE_BLOCK) {
            throw new AiPrivacyViolationException('Provider-bound '.$operation.' payload contained personal data: '.implode(', ', array_keys($counts)).'.');
        }

        Log::warning('AI egress guard redacted provider-bound data', ['operation' => $operation, 'redacted' => $counts]);
    }
}
