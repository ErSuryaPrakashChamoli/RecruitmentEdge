<?php

namespace App\Services\AI\Tools\KnowledgeTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Rag\VectorSearch;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\AiAssistantService;

/**
 * Semantic (embedding) search over the knowledge base when RAG is enabled/configured, falling
 * back to the original keyword search (AiAssistantService) otherwise — the base app keeps working
 * even with AI switched off (spec section 59).
 */
class SearchKnowledgeBaseTool implements AiTool
{
    public function __construct(
        private readonly VectorSearch $vectorSearch,
        private readonly AiAssistantService $keywordSearch,
    ) {}

    public function name(): string
    {
        return 'search_knowledge_base';
    }

    public function description(): string
    {
        return 'Search internal recruitment knowledge (SOPs, HR policies, hiring guidelines, uploaded documents) for an answer.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'required' => ['query'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'ai.query';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            return ToolResult::fail('A search query is required.');
        }

        $hits = $this->vectorSearch->search($query);

        if ($hits->isNotEmpty()) {
            return ToolResult::ok(
                data: ['results' => $hits->toArray(), 'method' => 'semantic'],
                summary: "Found {$hits->count()} relevant knowledge base excerpt(s).",
                type: 'source_list',
            );
        }

        $articles = $this->keywordSearch->search($query);

        return ToolResult::ok(
            data: [
                'results' => $articles->map(fn ($a) => ['title' => $a->title, 'content' => $a->content, 'category' => $a->category])->toArray(),
                'method' => 'keyword',
            ],
            summary: $articles->isEmpty()
                ? 'No matching knowledge base content found.'
                : "Found {$articles->count()} matching article(s).",
            type: 'source_list',
        );
    }
}
