<?php

namespace App\Models;

use App\Jobs\AI\ReindexKnowledgeArticleJob;
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
    use HasFactory;

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
                ReindexKnowledgeArticleJob::dispatch($article->id);
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
}
