<?php

namespace App\Models;

use App\Jobs\AI\ReindexKnowledgeArticleJob;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AiKnowledgeArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['title', 'slug', 'category', 'content', 'is_published', 'created_by'])]
class AiKnowledgeArticle extends Model
{
    /** @use HasFactory<AiKnowledgeArticleFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $article): void {
            if (blank($article->slug)) {
                $article->slug = Str::slug($article->title).'-'.Str::random(6);
            }
        });

        static::saved(function (self $article): void {
            if ($article->is_published) {
                // Phase 8.7 (D8.7-006): only once the save is committed.
                ReindexKnowledgeArticleJob::dispatch($article->id)->afterCommit();
            }
        });
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * RAG chunks embedded from this article's content — see AiDocument::chunks() for the sibling
     * relation and App\Services\AI\Rag\DocumentIngestionService for how these get populated.
     *
     * @return HasMany<AiDocumentChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(AiDocumentChunk::class, 'source_id')->where('source_type', 'knowledge_article');
    }

    /**
     * Phase 8.6 (D8.6-025): the article body is recorded as changed, never copied into the audit trail.
     *
     * @return array<int, string>
     */
    public function auditRedactedAttributes(): array
    {
        return ['content'];
    }
}
