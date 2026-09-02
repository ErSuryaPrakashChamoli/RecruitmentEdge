<?php

namespace App\Services\AI\DTO;

/**
 * Result of executing one AiTool::handle(). `type` lets the UI pick a structured render (candidate
 * card, KPI card, comparison table, ...) instead of forcing everything through plain text.
 */
final class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $data = [],
        public readonly ?string $summary = null,
        public readonly ?string $type = null,
        public readonly ?string $error = null,
    ) {}

    public static function ok(array $data, ?string $summary = null, ?string $type = null): self
    {
        return new self(true, $data, $summary, $type);
    }

    public static function fail(string $error): self
    {
        return new self(false, [], null, null, $error);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'data' => $this->data,
            'summary' => $this->summary,
            'type' => $this->type,
            'error' => $this->error,
        ];
    }
}
