<?php

namespace App\Http\Resources\Api\V1;

use App\Models\JobPosting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SaaS-6 API v1: a job posting — what the careers site publishes, plus its state.
 *
 * @property-read JobPosting $resource
 */
class JobPostingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $posting = $this->resource;

        return [
            'id' => $posting->getKey(),
            'requisition_id' => $posting->requisition_id,
            'title' => $posting->title,
            'summary' => $posting->summary,
            'status' => $posting->status?->value,
            'is_live' => $posting->isLive(),
            'closes_at' => $posting->closes_at?->toDateString(),
            'published_at' => $posting->published_at?->toIso8601String(),
            'created_at' => $posting->created_at?->toIso8601String(),
            'updated_at' => $posting->updated_at?->toIso8601String(),
        ];
    }
}
