<?php

namespace App\Models;

use App\Enums\AiDocumentStatus;
use App\Jobs\AI\IndexAiDocumentJob;
use Database\Factories\AiDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title',
    'category',
    'disk',
    'file_path',
    'mime_type',
    'uploaded_by',
    'is_published',
    'status',
    'error',
    'privacy_declared_at',
    'privacy_declared_by',
])]
class AiDocument extends Model
{
    /** @use HasFactory<AiDocumentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'status' => AiDocumentStatus::class,
            'privacy_declared_at' => 'datetime',
            'pii_redactions' => 'integer',
        ];
    }

    /**
     * Phase 8.1: only documents an administrator has declared free of personal data are
     * retrievable by the AI.
     */
    public function isPrivacyDeclared(): bool
    {
        return $this->privacy_declared_at !== null;
    }

    protected static function booted(): void
    {
        static::created(function (self $document): void {
            // Phase 8.7 (D8.7-006): only once the upload is committed.
            IndexAiDocumentJob::dispatch($document->id)->afterCommit();
        });
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'uploaded_by');
    }

    /**
     * `source_type` on AiDocumentChunk is a plain label ('document' | 'knowledge_article'), not an
     * Eloquent morph-map class name, so this is a manual HasMany keyed on source_id rather than a
     * real morphMany — see AiKnowledgeArticle::chunks() for the sibling relation.
     *
     * @return HasMany<AiDocumentChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(AiDocumentChunk::class, 'source_id')->where('source_type', 'document');
    }
}
