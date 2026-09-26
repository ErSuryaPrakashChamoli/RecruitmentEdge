<?php

namespace App\Models;

use App\Enums\DistributionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One posting on one channel. Written only by JobDistributionService / PublishJobDistributionJob
 * from the connector's reported result.
 */
#[Fillable(['job_posting_id', 'channel'])]
class JobDistribution extends Model
{
    protected function casts(): array
    {
        return [
            'status' => DistributionStatus::class,
            'attempts' => 'integer',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function posting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_posting_id');
    }
}
