<?php

namespace App\Models;

use Database\Factories\RecruitmentPipelineTemplateVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Phase 8.6 (D8.6-018): one immutable version of a pipeline template's stage list — stage code,
 * name, milestone, order, SLA and skippable flag as they were in that version.
 */
#[Fillable(['pipeline_template_id', 'version', 'stages', 'created_by'])]
class RecruitmentPipelineTemplateVersion extends Model
{
    /** @use HasFactory<RecruitmentPipelineTemplateVersionFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A pipeline template version is immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('A pipeline template version is never deleted — requisitions record it.');
        });
    }

    protected function casts(): array
    {
        return [
            'stages' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RecruitmentPipelineTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(RecruitmentPipelineTemplate::class, 'pipeline_template_id');
    }
}
