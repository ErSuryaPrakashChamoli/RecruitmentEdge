<?php

namespace App\Models;

use Database\Factories\AiDocumentChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A chunk of embedded text from either an AiDocument (source_type 'document') or an
 * AiKnowledgeArticle (source_type 'knowledge_article'), unified so semantic search covers both.
 * See App\Services\AI\Rag\VectorSearch.
 */
#[Fillable([
    'source_type',
    'source_id',
    'chunk_index',
    'content',
    'embedding',
    'token_count',
])]
class AiDocumentChunk extends Model
{
    /** @use HasFactory<AiDocumentChunkFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
        ];
    }
}
